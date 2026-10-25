<?php
/**
 * Stripe Payments Bridge & WP Connectors Integration for Card Vault POS.
 *
 * Facilitates Stripe Checkout Session generation, mobile QR tendering,
 * and order reconciliation using credentials from the WP Connectors API.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Stripe {

	/**
	 * Tenders the POS accepts. Any other paymentMethod is refused before an order is created.
	 */
	const POS_TENDERS = array( 'stripe', 'cash', 'split', 'store_credit' );

	/**
	 * Value written to the order's created_via and the Stripe session's metadata.app_source.
	 */
	const POS_SOURCE = 'card_vault_pos';

	/**
	 * Retrieve the Stripe secret key from the stripe_secret_key connector (Settings > Connectors).
	 *
	 * @return string
	 */
	public static function get_secret_key(): string {
		return Card_Vault_Connectors::get_key( Card_Vault_Connectors::STRIPE_SECRET_KEY );
	}

	/**
	 * Retrieve the Stripe publishable key from the stripe_publishable_key connector.
	 * It is a public identifier by design; the POS config route hands it to Stripe.js.
	 *
	 * @return string
	 */
	public static function get_publishable_key(): string {
		return Card_Vault_Connectors::get_key( Card_Vault_Connectors::STRIPE_PUBLISHABLE_KEY );
	}

	/**
	 * Check if Stripe is configured on the server.
	 *
	 * @return bool
	 */
	public static function is_stripe_configured(): bool {
		$key = self::get_secret_key();
		return ! empty( $key ) && strpos( $key, 'sk_test_Mock' ) !== 0;
	}

	/**
	 * Mark an unpaid POS order failed so it does not linger as pending. Best effort.
	 *
	 * @param mixed $order WC_Order or null.
	 */
	private static function fail_pos_order( $order ): void {
		if ( $order && is_object( $order ) && method_exists( $order, 'update_status' ) ) {
			$order->update_status( 'failed', 'Stripe checkout session could not be created.' );
		}
	}

	/**
	 * Create a WooCommerce POS order and generate a Stripe Checkout Session or QR link.
	 *
	 * Pricing is decided here, not by the client:
	 * - A cart line that resolves to a WooCommerce product (vault id, then productId) is
	 *   priced at that product's current WooCommerce price. The client's unitPrice is ignored.
	 * - A line that resolves to no product (a custom line), a discount and a tip are accepted
	 *   only when $is_dealer is true. Otherwise the request is refused with a 403.
	 * - The cashier recorded on the order is always the current user; payload cashierId is ignored.
	 * - For the 'stripe' tender, Stripe is asked to charge exactly the WooCommerce order total
	 *   (after discount, tip and any tax WooCommerce adds), in the order's currency.
	 *
	 * @param array $payload   POS checkout arguments.
	 * @param bool  $is_dealer Whether the current user holds a dealer capability.
	 * @return array|WP_Error Order and checkout session details or WP_Error.
	 */
	public static function create_pos_checkout( array $payload, bool $is_dealer = false ) {
		$items          = $payload['items'] ?? array();
		$payment_method = sanitize_key( $payload['paymentMethod'] ?? 'stripe' );
		$cashier_id     = get_current_user_id();
		$customer_name  = sanitize_text_field( $payload['customerName'] ?? '' );
		$customer_email = sanitize_email( $payload['customerEmail'] ?? '' );
		$customer_phone = sanitize_text_field( $payload['customerPhone'] ?? '' );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error( 'empty_cart', __( 'Cart contains no items.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $payment_method, self::POS_TENDERS, true ) ) {
			return new WP_Error( 'invalid_tender', __( 'paymentMethod must be one of: stripe, cash, split, store_credit.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		$discount   = round( max( 0.00, floatval( $payload['discount'] ?? 0.00 ) ), 2 );
		$tip_amount = round( max( 0.00, floatval( $payload['tipAmount'] ?? 0.00 ) ), 2 );
		if ( ! $is_dealer && ( $discount > 0.00 || $tip_amount > 0.00 ) ) {
			return new WP_Error( 'adjustment_not_allowed', __( 'Only a dealer can apply a discount or tip.', 'xophz-compass-card-vault' ), array( 'status' => 403 ) );
		}

		$lines = self::resolve_pos_lines( $items, $is_dealer );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$secret_key = '';
		if ( 'stripe' === $payment_method ) {
			$secret_key = self::get_secret_key();
			if ( empty( $secret_key ) ) {
				return new WP_Error(
					'missing_stripe_key',
					__( 'Stripe secret key is not configured. Add it under Settings > Connectors (Stripe Secret Key).', 'xophz-compass-card-vault' ),
					array( 'status' => 500 )
				);
			}
		}

		$line_total = 0.00;
		foreach ( $lines as $line ) {
			$line_total += $line['price'] * $line['qty'];
		}
		$line_total = round( $line_total, 2 );
		$discount   = min( $discount, $line_total );

		$order = null;
		if ( class_exists( 'WC_Order' ) ) {
			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				return new WP_Error( 'order_create_failed', $order->get_error_message(), array( 'status' => 500 ) );
			}
		}

		$order_id = $order ? $order->get_id() : time();

		if ( $order ) {
			foreach ( $lines as $line ) {
				$subtotal = $line['price'] * $line['qty'];
				if ( $line['product'] ) {
					$order->add_product( $line['product'], $line['qty'], array( 'subtotal' => $subtotal, 'total' => $subtotal ) );
					continue;
				}
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( $line['title'] );
				$fee->set_amount( $subtotal );
				$fee->set_total( $subtotal );
				$order->add_item( $fee );
			}

			if ( $discount > 0.00 ) {
				$discount_item = new WC_Order_Item_Fee();
				$discount_item->set_name( 'POS Discount' );
				$discount_item->set_amount( -$discount );
				$discount_item->set_total( -$discount );
				$order->add_item( $discount_item );
			}

			if ( $tip_amount > 0.00 ) {
				$tip_item = new WC_Order_Item_Fee();
				$tip_item->set_name( 'Staff Tip' );
				$tip_item->set_amount( $tip_amount );
				$tip_item->set_total( $tip_amount );
				$order->add_item( $tip_item );
				$order->update_meta_data( '_pos_tip_amount', $tip_amount );
			}

			$order->set_payment_method( $payment_method );
			$order->set_payment_method_title( $payment_method === 'stripe' ? 'Stripe Terminal / QR' : ucfirst( $payment_method ) );
			$order->set_created_via( self::POS_SOURCE );
			$order->update_meta_data( '_pos_cashier_id', $cashier_id );

			if ( ! empty( $customer_name ) ) {
				$parts = explode( ' ', $customer_name, 2 );
				$order->set_billing_first_name( $parts[0] );
				$order->set_billing_last_name( $parts[1] ?? '' );
			}
			if ( ! empty( $customer_email ) ) {
				$order->set_billing_email( $customer_email );
			}
			if ( ! empty( $customer_phone ) ) {
				$order->set_billing_phone( $customer_phone );
			}

			$order->calculate_totals();
			$order->save();
		}

		// The amount the customer owes: the saved WooCommerce order total when WooCommerce is active.
		$total_amount = $order ? round( (float) $order->get_total(), 2 ) : round( max( 0.00, $line_total - $discount + $tip_amount ), 2 );

		// Handle Stripe Payment Method
		if ( 'stripe' === $payment_method ) {
			$currency    = $order ? strtolower( $order->get_currency() ) : 'usd';
			$success_url = home_url( '/?xophz_card_vault_pos_complete=1&order_id=' . $order_id . '&session_id={CHECKOUT_SESSION_ID}' );
			$cancel_url  = home_url( '/?xophz_card_vault_pos_cancel=1&order_id=' . $order_id );

			$session_args = array(
				'payment_method_types' => array( 'card' ),
				'line_items'           => self::build_stripe_line_items( $lines, $tip_amount, $total_amount, $currency, $order_id ),
				'mode'                 => 'payment',
				'success_url'          => $success_url,
				'cancel_url'           => $cancel_url,
				'metadata'             => array(
					'order_id'   => (string) $order_id,
					'app_source' => self::POS_SOURCE,
				),
			);

			$response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( $secret_key ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $session_args ),
				'timeout' => 15,
			) );

			if ( is_wp_error( $response ) ) {
				self::fail_pos_order( $order );
				return new WP_Error( 'stripe_http_error', $response->get_error_message(), array( 'status' => 500 ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['error'] ) ) {
				self::fail_pos_order( $order );
				return new WP_Error( 'stripe_api_error', $body['error']['message'] ?? '', array( 'status' => 400 ) );
			}
			if ( ! is_array( $body ) || empty( $body['url'] ) ) {
				self::fail_pos_order( $order );
				return new WP_Error( 'stripe_api_error', __( 'Stripe did not return a checkout URL.', 'xophz-compass-card-vault' ), array( 'status' => 502 ) );
			}

			$checkout_url = $body['url'];
			$session_id   = $body['id'] ?? '';
			$qr_url       = 'https://api.qrserver.com/v1/create-qr-code/?size=250&data=' . urlencode( $checkout_url );

			if ( $order ) {
				$order->update_meta_data( '_stripe_session_id', $session_id );
				$order->update_meta_data( '_stripe_checkout_url', $checkout_url );
				$order->save();
			}

			return array(
				'orderId'        => $order_id,
				'paymentMethod'  => 'stripe',
				'totalAmount'    => $total_amount,
				'checkoutUrl'    => $checkout_url,
				'sessionId'      => $session_id,
				'qrCodeUrl'      => $qr_url,
				'status'         => 'pending_payment',
				'receiptKey'     => $order ? $order->get_order_key() : '',
			);
		}

		// Handle Immediate Tenders (Cash, Store Credit, Split)
		if ( $order ) {
			$order->set_status( 'completed', 'Completed via Card Vault POS (' . ucfirst( $payment_method ) . ')' );
			$order->save();
			Card_Vault_WC_Sync::handle_order_completed( $order_id );
		}

		return array(
			'orderId'       => $order_id,
			'paymentMethod' => $payment_method,
			'totalAmount'   => $total_amount,
			'status'        => 'completed',
			'receiptUrl'    => home_url( '/?xophz_bazaar_receipt=' . $order_id . '&key=' . ( $order ? $order->get_order_key() : '' ) ),
			'receiptKey'    => $order ? $order->get_order_key() : '',
		);
	}

	/**
	 * Resolve and price every cart line before any order is created, so a refused cart
	 * leaves no order behind.
	 *
	 * @param array $items     Cart items from the POS payload.
	 * @param bool  $is_dealer Whether custom (non-catalog) lines are allowed.
	 * @return array|WP_Error List of array{product: WC_Product|null, title: string, subtitle: string, qty: int, price: float}.
	 */
	private static function resolve_pos_lines( array $items, bool $is_dealer ) {
		$lines = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'invalid_item', __( 'Each cart item must be an object.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
			}

			$title    = sanitize_text_field( $item['title'] ?? 'Trading Card' );
			$subtitle = ! empty( $item['subtitle'] ) ? sanitize_text_field( $item['subtitle'] ) : 'Card Vault Inventory Item';
			$qty      = max( 1, intval( $item['quantity'] ?? 1 ) );
			$vault_id = sanitize_text_field( $item['id'] ?? '' );
			$product  = null;

			if ( class_exists( 'WC_Order' ) ) {
				$product_id = '' !== $vault_id ? (int) Card_Vault_WC_Sync::find_product_by_vault_id( $vault_id ) : 0;
				if ( ! $product_id && ! empty( $item['productId'] ) ) {
					$product_id = intval( $item['productId'] );
				}
				$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
			}

			if ( $product ) {
				$wc_price = $product->get_price();
				if ( '' === $wc_price || null === $wc_price ) {
					return new WP_Error(
						'unpriced_product',
						/* translators: %s: product name */
						sprintf( __( '%s has no price set in WooCommerce.', 'xophz-compass-card-vault' ), $product->get_name() ),
						array( 'status' => 400 )
					);
				}
				$price = round( max( 0.00, (float) $wc_price ), 2 );
				$title = $product->get_name();
			} elseif ( $is_dealer ) {
				$price = round( max( 0.00, floatval( $item['unitPrice'] ?? 0.00 ) ), 2 );
			} else {
				return new WP_Error( 'unknown_product', __( 'Every cart item must match a store product.', 'xophz-compass-card-vault' ), array( 'status' => 403 ) );
			}

			$lines[] = array(
				'product'  => $product ? $product : null,
				'title'    => $title,
				'subtitle' => $subtitle,
				'qty'      => $qty,
				'price'    => $price,
			);
		}

		return $lines;
	}

	/**
	 * Build Stripe Checkout line items that charge exactly $total_amount.
	 *
	 * The cart is itemized (plus a tip line) when those lines add up to the total to the cent.
	 * When a discount, tax or rounding makes them differ, one line for the order total is sent
	 * instead, so the Stripe charge always equals the WooCommerce order total.
	 *
	 * @param array  $lines        Resolved cart lines.
	 * @param float  $tip_amount   Tip added to the order.
	 * @param float  $total_amount Amount the customer owes.
	 * @param string $currency     Lowercase ISO currency code.
	 * @param int    $order_id     Order identifier, used in the single-line name.
	 * @return array
	 */
	private static function build_stripe_line_items( array $lines, float $tip_amount, float $total_amount, string $currency, int $order_id ): array {
		$charge_cents = (int) round( $total_amount * 100 );
		$items_cents  = 0;
		$itemized     = array();
		$titles       = array();

		foreach ( $lines as $line ) {
			$unit_cents   = (int) round( $line['price'] * 100 );
			$items_cents += $unit_cents * $line['qty'];
			$titles[]     = $line['qty'] > 1 ? $line['qty'] . ' x ' . $line['title'] : $line['title'];
			$itemized[]   = array(
				'price_data' => array(
					'currency'     => $currency,
					'product_data' => array(
						'name'        => $line['title'],
						'description' => $line['subtitle'],
					),
					'unit_amount'  => $unit_cents,
				),
				'quantity'   => $line['qty'],
			);
		}

		if ( $tip_amount > 0.00 ) {
			$tip_cents    = (int) round( $tip_amount * 100 );
			$items_cents += $tip_cents;
			$itemized[]   = array(
				'price_data' => array(
					'currency'     => $currency,
					'product_data' => array( 'name' => 'Staff Tip' ),
					'unit_amount'  => $tip_cents,
				),
				'quantity'   => 1,
			);
		}

		if ( $items_cents === $charge_cents ) {
			return $itemized;
		}

		return array(
			array(
				'price_data' => array(
					'currency'     => $currency,
					'product_data' => array(
						/* translators: %d: order id */
						'name'        => sprintf( __( 'Card Vault POS order #%d', 'xophz-compass-card-vault' ), $order_id ),
						'description' => wp_html_excerpt( implode( ', ', $titles ), 250, '...' ),
					),
					'unit_amount'  => $charge_cents,
				),
				'quantity'   => 1,
			),
		);
	}

	/**
	 * Verify a Stripe Checkout Session and settle the POS order it was created for.
	 *
	 * The order is settled only when all of these hold; otherwise a 409 is returned and nothing changes:
	 * - the order exists, was created by the Card Vault POS, and stores this exact session id;
	 * - Stripe returns that session with metadata.order_id equal to the order id and
	 *   metadata.app_source equal to card_vault_pos;
	 * - the session's amount_total equals the order total in cents and its currency matches the order.
	 * A session settles its order at most once: a repeat call (for example while the POS polls)
	 * reports the session status without settling again, even if the order was later cancelled.
	 *
	 * @param int    $order_id Order identifier.
	 * @param string $session_id Stripe checkout session ID.
	 * @return array|WP_Error Result descriptor or WP_Error.
	 */
	public static function verify_payment( int $order_id, string $session_id ) {
		if ( ! preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $session_id ) ) {
			return new WP_Error( 'invalid_session', __( 'sessionId is not a Stripe Checkout session id.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_inactive', __( 'WooCommerce is not active, so there is no order to settle.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$mismatch = new WP_Error( 'session_order_mismatch', __( 'This Stripe session does not belong to this order.', 'xophz-compass-card-vault' ), array( 'status' => 409 ) );

		// Bind the session to the order before calling Stripe, so unrelated ids never reach the API.
		$order          = wc_get_order( $order_id );
		$stored_session = $order ? (string) $order->get_meta( '_stripe_session_id' ) : '';
		if ( ! $order || self::POS_SOURCE !== $order->get_created_via() || '' === $stored_session || ! hash_equals( $stored_session, $session_id ) ) {
			return $mismatch;
		}

		$secret_key = self::get_secret_key();
		if ( empty( $secret_key ) ) {
			return new WP_Error( 'missing_stripe_key', __( 'Stripe secret key missing.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$response = wp_remote_get( 'https://api.stripe.com/v1/checkout/sessions/' . urlencode( $session_id ), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $secret_key ),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_verify_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'stripe_api_error', __( 'Stripe returned an unreadable response.', 'xophz-compass-card-vault' ), array( 'status' => 502 ) );
		}
		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'stripe_api_error', $body['error']['message'] ?? 'Stripe error', array( 'status' => 400 ) );
		}

		if ( ! self::session_matches_order( $body, $order, $session_id ) ) {
			return $mismatch;
		}

		$payment_status  = $body['payment_status'] ?? 'unpaid';
		$is_paid         = ( 'paid' === $payment_status );
		$already_settled = '' !== (string) $order->get_meta( '_card_vault_stripe_settled' );

		if ( $is_paid && ! $already_settled && ! $order->is_paid() ) {
			$order->update_meta_data( '_card_vault_stripe_settled', $session_id );
			$order->save();

			$transaction_id = isset( $body['payment_intent'] ) && is_string( $body['payment_intent'] ) ? $body['payment_intent'] : $session_id;
			if ( $order->payment_complete( $transaction_id ) ) {
				$order->add_order_note( 'Stripe POS mobile payment confirmed. Session: ' . $session_id );
				Card_Vault_WC_Sync::handle_order_completed( $order_id );
			} else {
				$order->delete_meta_data( '_card_vault_stripe_settled' );
				$order->save();
			}
		}

		return array(
			'orderId'       => $order_id,
			'sessionId'     => $session_id,
			'paymentStatus' => $payment_status,
			'isPaid'        => $is_paid,
		);
	}

	/**
	 * Check that a Stripe Checkout Session was created for this POS order and charges its total.
	 *
	 * @param array    $session    Decoded Stripe session.
	 * @param WC_Order $order      The order the caller wants settled.
	 * @param string   $session_id The session id the caller sent.
	 * @return bool
	 */
	private static function session_matches_order( array $session, $order, string $session_id ): bool {
		$metadata = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();

		return ( $session['id'] ?? '' ) === $session_id
			&& (string) ( $metadata['order_id'] ?? '' ) === (string) $order->get_id()
			&& ( $metadata['app_source'] ?? '' ) === self::POS_SOURCE
			&& isset( $session['amount_total'] )
			&& (int) $session['amount_total'] === (int) round( (float) $order->get_total() * 100 )
			&& strtolower( (string) ( $session['currency'] ?? '' ) ) === strtolower( (string) $order->get_currency() );
	}
}
