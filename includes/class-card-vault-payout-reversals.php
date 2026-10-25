<?php
/**
 * Consignor payout reversals: void or claw back a credit when a WooCommerce sale is undone.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Payout status transitions driven by order cancellations, failures and refunds.
 *
 * Statuses in the payouts table:
 * - 'unpaid':   credited to the consignor, not settled yet.
 * - 'paid':     settled. A paid row is never edited here, so it stays the record of what was disbursed.
 * - 'void':     the sale was undone before settlement. The row keeps its amounts for audit
 *               and counts toward no totals.
 * - 'clawback': a separate row (sale_record_id + '_clawback') holding negative deltas for a
 *               sale undone after settlement. Minus its consignor_payout_amount is what the
 *               consignor owes back.
 *
 * What this does not do: it moves no money and recovers nothing; it only records what is
 * owed. A refund WooCommerce does not allocate to a line item (an amount-only partial refund)
 * is not attributed to any consigned item and changes no payout. A void row is not credited
 * again if the order is later completed again.
 */
class Card_Vault_Payout_Reversals {

	const CLAWBACK_SUFFIX = '_clawback';

	/**
	 * Register the WooCommerce hooks that reverse consignor credits.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'handle_order_reversed' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'handle_order_reversed' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'handle_order_reversed' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'handle_order_refunded' ), 10, 2 );
	}

	/**
	 * The idempotent sale_record_id of a WooCommerce order line item.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id Order item ID.
	 * @return string
	 */
	public static function order_item_sale_record_id( $order_id, $item_id ) {
		return sprintf( 'wc_order_%d_item_%d', (int) $order_id, (int) $item_id );
	}

	/**
	 * What is left of a line item after the refunds WooCommerce allocated to it.
	 *
	 * @param WC_Order      $order Order.
	 * @param int           $item_id Order item ID.
	 * @param WC_Order_Item $item Order line item.
	 * @return array { amount: float line total net of refunds, quantity: int units net of refunds }
	 */
	public static function remaining_for_item( $order, $item_id, $item ) {
		$refunded_amount = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
		$refunded_qty    = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

		return array(
			'amount'   => max( 0.0, round( (float) $item->get_total() - $refunded_amount, 2 ) ),
			'quantity' => max( 0, (int) $item->get_quantity() - $refunded_qty ),
		);
	}

	/**
	 * Hook: order cancelled, refunded in full or failed. Undo every consignor credit of the order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function handle_order_reversed( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$status = $order ? $order->get_status() : '';
		$reason = sprintf( 'order #%d moved to status "%s"', $order_id, $status ? $status : 'unknown' );
		$result = self::void_payouts_for_order( $order_id, $reason );

		if ( $order && ( $result['void'] + $result['clawback'] ) > 0 ) {
			$order->add_order_note( sprintf(
				'Card Vault: %d unpaid consignor credit(s) voided; %d settled credit(s) recorded as clawback owed by the consignor.',
				$result['void'],
				$result['clawback']
			) );
		}
	}

	/**
	 * Hook: a refund was created (partial or full). Bring each consigned line item's payout
	 * down to what is left of the line after its allocated refunds.
	 *
	 * @param int $order_id Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public static function handle_order_refunded( $order_id, $refund_id = 0 ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order ) {
			return;
		}

		// Cancelled, refunded and failed orders were already fully reversed by the status hooks.
		// Re-reconciling would see an amount-only refund as 0 allocated and overwrite the clawback.
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}

		$changed = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			$remaining = self::remaining_for_item( $order, $item_id, $item );
			$outcome   = self::reconcile_payout(
				self::order_item_sale_record_id( $order_id, $item_id ),
				$remaining['amount'],
				$remaining['quantity'],
				sprintf( 'refund #%d on order #%d', (int) $refund_id, (int) $order_id )
			);
			if ( in_array( $outcome, array( 'reduced', 'void', 'clawback' ), true ) ) {
				$changed++;
			}
		}

		if ( $changed > 0 ) {
			$order->add_order_note( sprintf(
				'Card Vault: refund #%d adjusted %d consignor payout(s) (unpaid credits reduced or voided; settled credits recorded as clawback).',
				(int) $refund_id,
				$changed
			) );
		}
	}

	/**
	 * Undo every unpaid or paid consignor credit recorded against an order.
	 *
	 * Unpaid rows become 'void'. Paid rows get a 'clawback' row for their full consignor
	 * share. Running it again changes nothing.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $reason Why the credits are undone (stored in the row notes).
	 * @return array { void: int rows voided by this call, clawback: int clawback rows created or changed by this call }
	 */
	public static function void_payouts_for_order( $order_id, $reason ) {
		global $wpdb;
		$result   = array( 'void' => 0, 'clawback' => 0 );
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return $result;
		}

		$table = Card_Vault_Consignments::get_payouts_table();
		$ids   = $wpdb->get_col( $wpdb->prepare(
			"SELECT sale_record_id FROM {$table} WHERE wc_order_id = %d AND payout_status IN ('unpaid', 'paid')",
			$order_id
		) );

		foreach ( (array) $ids as $sale_record_id ) {
			$outcome = self::reconcile_payout( $sale_record_id, 0, 0, $reason );
			if ( isset( $result[ $outcome ] ) ) {
				$result[ $outcome ]++;
			}
		}

		return $result;
	}

	/**
	 * Bring one payout in line with what is left of its sale.
	 *
	 * The remaining amount and quantity are absolute (what is left of the sale now), so the
	 * call is idempotent: repeating it with the same values changes nothing.
	 * - unpaid row, nothing left: status 'void', amounts kept for audit.
	 * - unpaid row, part left: amounts recomputed at the row's own split rate.
	 * - paid row: the paid row is left as is; a clawback row records what the consignor owes back.
	 * - void or clawback row: left as is.
	 *
	 * @param string $sale_record_id Payout sale_record_id.
	 * @param float  $remaining_amount Sale amount still standing.
	 * @param int    $remaining_quantity Units still sold.
	 * @param string $reason Why (stored in the row notes).
	 * @return string 'missing' | 'unchanged' | 'reduced' | 'void' | 'clawback'
	 */
	public static function reconcile_payout( $sale_record_id, $remaining_amount, $remaining_quantity, $reason ) {
		$row = Card_Vault_Consignments::get_payout_by_sale_record_id( $sale_record_id );
		if ( ! $row ) {
			return 'missing';
		}

		$remaining_amount   = max( 0.0, round( (float) $remaining_amount, 2 ) );
		$remaining_quantity = max( 0, (int) $remaining_quantity );

		if ( 'unpaid' === $row['payout_status'] ) {
			$outcome = self::reduce_unpaid( $row, $remaining_amount, $remaining_quantity, $reason );
			if ( 'raced' !== $outcome ) {
				return $outcome;
			}
			// The row was settled between our read and our update: treat it as paid.
			$row = Card_Vault_Consignments::get_payout_by_sale_record_id( $sale_record_id );
			if ( ! $row ) {
				return 'missing';
			}
		}

		if ( 'paid' === $row['payout_status'] ) {
			return self::record_clawback( $row, $remaining_amount, $remaining_quantity, $reason );
		}

		return 'unchanged';
	}

	/**
	 * Void or reduce an unpaid row. The update only matches while the row is still unpaid.
	 *
	 * @param array  $row Payout row.
	 * @param float  $remaining_amount Sale amount still standing.
	 * @param int    $remaining_quantity Units still sold.
	 * @param string $reason Why.
	 * @return string 'unchanged' | 'reduced' | 'void' | 'raced'
	 */
	private static function reduce_unpaid( $row, $remaining_amount, $remaining_quantity, $reason ) {
		global $wpdb;
		$table = Card_Vault_Consignments::get_payouts_table();

		if ( $remaining_amount <= 0 ) {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET payout_status = 'void', notes = CONCAT_WS(%s, NULLIF(notes, ''), %s) WHERE id = %d AND payout_status = 'unpaid'",
				"\n",
				self::note( sprintf( 'Voided before settlement: %s.', $reason ) ),
				(int) $row['id']
			) );
			return 1 === $updated ? 'void' : 'raced';
		}

		$current_total = round( (float) $row['total_sale_amount'], 2 );
		if ( abs( $current_total - $remaining_amount ) < 0.005 && (int) $row['quantity'] === $remaining_quantity ) {
			return 'unchanged';
		}

		$payout  = round( $remaining_amount * ( (float) $row['consignor_split_rate'] / 100.0 ), 2 );
		$profit  = round( $remaining_amount - $payout, 2 );
		$note    = self::note( sprintf(
			'Adjusted before settlement from $%0.2f (consignor $%0.2f) to $%0.2f (consignor $%0.2f): %s.',
			$current_total,
			(float) $row['consignor_payout_amount'],
			$remaining_amount,
			$payout,
			$reason
		) );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET quantity = %d, total_sale_amount = %f, consignor_payout_amount = %f, dealer_profit_amount = %f, notes = CONCAT_WS(%s, NULLIF(notes, ''), %s) WHERE id = %d AND payout_status = 'unpaid'",
			$remaining_quantity,
			$remaining_amount,
			$payout,
			$profit,
			"\n",
			$note,
			(int) $row['id']
		) );

		return 1 === $updated ? 'reduced' : 'raced';
	}

	/**
	 * Create or update the clawback row for a paid payout whose sale shrank or was undone.
	 *
	 * The clawback row holds the delta between the paid row and what is left of the sale:
	 * negative quantity, sale amount and consignor share, so ledger sums are net of refunds.
	 *
	 * @param array  $row Paid payout row.
	 * @param float  $remaining_amount Sale amount still standing.
	 * @param int    $remaining_quantity Units still sold.
	 * @param string $reason Why.
	 * @return string 'unchanged' | 'clawback'
	 */
	private static function record_clawback( $row, $remaining_amount, $remaining_quantity, $reason ) {
		global $wpdb;
		$table = Card_Vault_Consignments::get_payouts_table();

		$paid_total   = round( (float) $row['total_sale_amount'], 2 );
		$paid_payout  = round( (float) $row['consignor_payout_amount'], 2 );
		$new_payout   = round( $remaining_amount * ( (float) $row['consignor_split_rate'] / 100.0 ), 2 );
		$owed         = max( 0.0, round( $paid_payout - $new_payout, 2 ) );
		$sale_delta   = min( 0.0, round( $remaining_amount - $paid_total, 2 ) );
		$qty_delta    = min( 0, $remaining_quantity - (int) $row['quantity'] );
		$profit_delta = round( $sale_delta + $owed, 2 );

		$clawback_id = self::clawback_record_id( $row['sale_record_id'] );
		$existing    = Card_Vault_Consignments::get_payout_by_sale_record_id( $clawback_id );

		if ( $existing ) {
			if ( 'clawback' !== $existing['payout_status'] ) {
				return 'unchanged'; // The id belongs to some other row: never overwrite it.
			}
			$same_owed = abs( -1 * (float) $existing['consignor_payout_amount'] - $owed ) < 0.005;
			$same_sale = abs( (float) $existing['total_sale_amount'] - $sale_delta ) < 0.005;
			if ( $same_owed && $same_sale ) {
				return 'unchanged';
			}
		} elseif ( $owed <= 0 && $sale_delta >= 0 ) {
			return 'unchanged';
		}

		$note = self::note( sprintf(
			'Clawback for settled payout #%d (%s): consignor was paid $%0.2f; after %s the sale stands at $%0.2f and the consignor share at $%0.2f, so $%0.2f is owed back. Recorded only: no money has moved.',
			(int) $row['id'],
			$row['sale_record_id'],
			$paid_payout,
			$reason,
			$remaining_amount,
			$new_payout,
			$owed
		) );

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				array(
					'quantity'                => $qty_delta,
					'total_sale_amount'       => $sale_delta,
					'consignor_payout_amount' => -1 * $owed,
					'dealer_profit_amount'    => $profit_delta,
					'notes'                   => $note,
				),
				array(
					'id'            => (int) $existing['id'],
					'payout_status' => 'clawback',
				),
				array( '%d', '%f', '%f', '%f', '%s' ),
				array( '%d', '%s' )
			);
			return $updated ? 'clawback' : 'unchanged';
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'sale_record_id'          => $clawback_id,
				'consignor_id'            => $row['consignor_id'],
				'wc_order_id'             => (int) $row['wc_order_id'],
				'wc_product_id'           => (int) $row['wc_product_id'],
				'inventory_item_id'       => (string) $row['inventory_item_id'],
				'card_id'                 => (string) $row['card_id'],
				'card_name'               => (string) $row['card_name'],
				'set_name'                => (string) $row['set_name'],
				'card_number'             => (string) $row['card_number'],
				'quantity'                => $qty_delta,
				'sale_price_per_unit'     => (float) $row['sale_price_per_unit'],
				'total_sale_amount'       => $sale_delta,
				'consignor_split_rate'    => (float) $row['consignor_split_rate'],
				'consignor_payout_amount' => -1 * $owed,
				'dealer_profit_amount'    => $profit_delta,
				'payout_status'           => 'clawback',
				'payment_method'          => '',
				'payment_reference'       => '',
				'sale_source'             => (string) $row['sale_source'],
				'notes'                   => $note,
				'created_at'              => current_time( 'mysql' ),
			),
			array(
				'%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s',
				'%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s',
				'%s', '%s', '%s',
			)
		);

		// A failed insert (for example a concurrent request inserted the same clawback id first) changes nothing.
		return $inserted ? 'clawback' : 'unchanged';
	}

	/**
	 * The sale_record_id of the clawback row for a payout. Fits the varchar(64) column.
	 *
	 * @param string $sale_record_id Payout sale_record_id.
	 * @return string
	 */
	private static function clawback_record_id( $sale_record_id ) {
		$sale_record_id = (string) $sale_record_id;
		if ( strlen( $sale_record_id ) + strlen( self::CLAWBACK_SUFFIX ) <= 64 ) {
			return $sale_record_id . self::CLAWBACK_SUFFIX;
		}
		return md5( $sale_record_id ) . self::CLAWBACK_SUFFIX;
	}

	/**
	 * A timestamped note line.
	 *
	 * @param string $text Note text.
	 * @return string
	 */
	private static function note( $text ) {
		return '[' . current_time( 'mysql' ) . '] ' . sanitize_textarea_field( $text );
	}
}
