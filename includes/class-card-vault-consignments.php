<?php
/**
 * Consignment accounting and split tracking engine.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/class-card-vault-payout-reversals.php';

class Card_Vault_Consignments {

	/**
	 * Get the consignments table name.
	 *
	 * @return string
	 */
	public static function get_consignments_table() {
		global $wpdb;
		return $wpdb->prefix . 'xophz_vault_consignments';
	}

	/**
	 * Get the payouts table name.
	 *
	 * @return string
	 */
	public static function get_payouts_table() {
		global $wpdb;
		return $wpdb->prefix . 'xophz_vault_payouts';
	}

	/**
	 * Retrieve a list of consignors.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_consignors( $args = array() ) {
		global $wpdb;
		$table = self::get_consignments_table();

		$defaults = array(
			'status'  => '',
			'search'  => '',
			'orderby' => 'created_at',
			'order'   => 'DESC',
			'limit'   => 50,
			'offset'  => 0,
		);
		$r = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $r['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( $r['status'] );
		}

		if ( ! empty( $r['search'] ) ) {
			$search_like = '%' . $wpdb->esc_like( sanitize_text_field( $r['search'] ) ) . '%';
			$where[]     = '(name LIKE %s OR email LIKE %s OR consignor_id LIKE %s)';
			$params[]    = $search_like;
			$params[]    = $search_like;
			$params[]    = $search_like;
		}

		$allowed_order_by = array( 'id', 'name', 'created_at', 'default_split_rate', 'status' );
		$orderby = in_array( $r['orderby'], $allowed_order_by, true ) ? $r['orderby'] : 'created_at';
		$order   = strtoupper( $r['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}";

		if ( $r['limit'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $r['limit'], (int) $r['offset'] );
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Retrieve a single consignor by consignor_id.
	 *
	 * @param string $consignor_id Custom unique string identifier.
	 * @return array|null
	 */
	public static function get_consignor( $consignor_id ) {
		global $wpdb;
		$table = self::get_consignments_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE consignor_id = %s LIMIT 1", sanitize_text_field( $consignor_id ) );
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Retrieve a consignor profile by linked WordPress User ID.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array|null
	 */
	public static function get_consignor_by_user_id( $wp_user_id ) {
		global $wpdb;
		$table = self::get_consignments_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d LIMIT 1", (int) $wp_user_id );
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Retrieve the consignor owned by a user through an exact email match.
	 *
	 * Matches only rows with no linked user (wp_user_id = 0) or already linked
	 * to this user. Never a substring match. Returns null for an empty email.
	 *
	 * @param string $email      Exact email address (case-insensitive under the default collation).
	 * @param int    $wp_user_id WordPress user ID of the requester.
	 * @return array|null
	 */
	public static function get_consignor_by_email( $email, $wp_user_id ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return null;
		}
		$table = self::get_consignments_table();
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE email = %s AND (wp_user_id = 0 OR wp_user_id = %d) ORDER BY wp_user_id DESC, id ASC LIMIT 1",
			$email,
			(int) $wp_user_id
		);
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Create a new consignor profile.
	 *
	 * @param array $data Consignor fields.
	 * @return string|WP_Error Returns consignor_id on success.
	 */
	public static function create_consignor( $data ) {
		global $wpdb;
		$table = self::get_consignments_table();

		if ( empty( $data['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Consignor name is required.', 'xophz-compass-card-vault' ) );
		}

		$consignor_id = ! empty( $data['consignor_id'] )
			? sanitize_text_field( $data['consignor_id'] )
			: 'consignor_' . wp_generate_uuid4();

		// Check uniqueness of consignor_id
		$existing = self::get_consignor( $consignor_id );
		if ( $existing ) {
			return new WP_Error( 'duplicate_consignor_id', __( 'Consignor ID already exists.', 'xophz-compass-card-vault' ) );
		}

		$wp_user_id         = isset( $data['wp_user_id'] ) ? (int) $data['wp_user_id'] : 0;
		$name               = sanitize_text_field( $data['name'] );
		$email              = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		$phone              = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';
		$default_split_rate = isset( $data['default_split_rate'] ) ? floatval( $data['default_split_rate'] ) : 85.00;
		if ( $default_split_rate < 0 || $default_split_rate > 100 ) {
			return new WP_Error( 'invalid_split_rate', __( 'Split rate must be between 0 and 100.', 'xophz-compass-card-vault' ) );
		}
		$payout_method      = isset( $data['payout_method'] ) ? sanitize_text_field( $data['payout_method'] ) : 'Cash';
		$payout_handle      = isset( $data['payout_handle'] ) ? sanitize_text_field( $data['payout_handle'] ) : '';
		$status             = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'active';
		$notes              = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '';

		$inserted = $wpdb->insert(
			$table,
			array(
				'consignor_id'       => $consignor_id,
				'wp_user_id'         => $wp_user_id,
				'name'               => $name,
				'email'              => $email,
				'phone'              => $phone,
				'default_split_rate' => $default_split_rate,
				'payout_method'      => $payout_method,
				'payout_handle'      => $payout_handle,
				'status'             => $status,
				'notes'              => $notes,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Failed to create consignor record.', 'xophz-compass-card-vault' ) );
		}

		return $consignor_id;
	}

	/**
	 * Update an existing consignor profile.
	 *
	 * @param string $consignor_id Consignor unique ID.
	 * @param array  $data Fields to update.
	 * @return bool|WP_Error
	 */
	public static function update_consignor( $consignor_id, $data ) {
		global $wpdb;
		$table = self::get_consignments_table();

		$existing = self::get_consignor( $consignor_id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Consignor not found.', 'xophz-compass-card-vault' ) );
		}

		$update_data   = array();
		$update_format = array();

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$update_format[]     = '%s';
		}
		if ( isset( $data['email'] ) ) {
			$update_data['email'] = sanitize_email( $data['email'] );
			$update_format[]      = '%s';
		}
		if ( isset( $data['phone'] ) ) {
			$update_data['phone'] = sanitize_text_field( $data['phone'] );
			$update_format[]      = '%s';
		}
		if ( isset( $data['wp_user_id'] ) ) {
			$update_data['wp_user_id'] = (int) $data['wp_user_id'];
			$update_format[]           = '%d';
		}
		if ( isset( $data['default_split_rate'] ) ) {
			$new_rate = floatval( $data['default_split_rate'] );
			if ( $new_rate < 0 || $new_rate > 100 ) {
				return new WP_Error( 'invalid_split_rate', __( 'Split rate must be between 0 and 100.', 'xophz-compass-card-vault' ) );
			}
			$update_data['default_split_rate'] = $new_rate;
			$update_format[]                   = '%f';
		}
		if ( isset( $data['payout_method'] ) ) {
			$update_data['payout_method'] = sanitize_text_field( $data['payout_method'] );
			$update_format[]              = '%s';
		}
		if ( isset( $data['payout_handle'] ) ) {
			$update_data['payout_handle'] = sanitize_text_field( $data['payout_handle'] );
			$update_format[]              = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $data['status'] );
			$update_format[]       = '%s';
		}
		if ( isset( $data['notes'] ) ) {
			$update_data['notes'] = sanitize_textarea_field( $data['notes'] );
			$update_format[]      = '%s';
		}

		if ( empty( $update_data ) ) {
			return true;
		}

		$updated = $wpdb->update(
			$table,
			$update_data,
			array( 'consignor_id' => $consignor_id ),
			$update_format,
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Soft delete or archive a consignor.
	 *
	 * @param string $consignor_id Consignor unique ID.
	 * @return bool
	 */
	public static function delete_consignor( $consignor_id ) {
		return self::update_consignor( $consignor_id, array( 'status' => 'archived' ) );
	}

	/**
	 * Record a sale payout transaction in the custom ledger.
	 * Idempotent: If sale_record_id already exists, return existing row ID.
	 *
	 * @param array $payout_data Payout details.
	 * @return int|WP_Error Primary key ID on success.
	 */
	public static function record_payout( $payout_data ) {
		global $wpdb;
		$table = self::get_payouts_table();

		if ( empty( $payout_data['sale_record_id'] ) ) {
			return new WP_Error( 'missing_sale_record_id', __( 'Sale record ID is required.', 'xophz-compass-card-vault' ) );
		}
		if ( empty( $payout_data['consignor_id'] ) ) {
			return new WP_Error( 'missing_consignor_id', __( 'Consignor ID is required.', 'xophz-compass-card-vault' ) );
		}

		$sale_record_id = sanitize_text_field( $payout_data['sale_record_id'] );

		// Idempotency check: look for existing record
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE sale_record_id = %s LIMIT 1", $sale_record_id ) );
		if ( ! empty( $existing ) ) {
			return (int) $existing;
		}

		$consignor_id = sanitize_text_field( $payout_data['consignor_id'] );
		$consignor    = self::get_consignor( $consignor_id );
		if ( ! $consignor ) {
			return new WP_Error( 'unknown_consignor', __( 'Consignor does not exist.', 'xophz-compass-card-vault' ) );
		}

		$quantity            = isset( $payout_data['quantity'] ) ? max( 1, (int) $payout_data['quantity'] ) : 1;
		$sale_price_per_unit = isset( $payout_data['sale_price_per_unit'] ) ? floatval( $payout_data['sale_price_per_unit'] ) : 0.00;
		$total_sale_amount   = isset( $payout_data['total_sale_amount'] ) ? floatval( $payout_data['total_sale_amount'] ) : ( $sale_price_per_unit * $quantity );
		if ( $total_sale_amount < 0 || $sale_price_per_unit < 0 ) {
			return new WP_Error( 'invalid_sale_amount', __( 'Sale amounts cannot be negative.', 'xophz-compass-card-vault' ) );
		}

		// Split rate: a positive per-item rate from the caller wins. Callers omit the key
		// (or pass null) when the item has no rate of its own, so the consignor's
		// default_split_rate applies, and 85.00 only when the consignor has none.
		$item_split_rate = $payout_data['consignor_split_rate'] ?? null;
		if ( is_numeric( $item_split_rate ) && (float) $item_split_rate > 0 ) {
			$split_rate = floatval( $item_split_rate );
		} elseif ( $consignor && isset( $consignor['default_split_rate'] ) && is_numeric( $consignor['default_split_rate'] ) && (float) $consignor['default_split_rate'] > 0 ) {
			$split_rate = floatval( $consignor['default_split_rate'] );
		} else {
			$split_rate = 85.00;
		}

		// Round once so the stored decimal(5,2) rate matches the rate used here, then bound it.
		$split_rate = max( 0.0, min( 100.0, round( $split_rate, 2 ) ) );

		$consignor_payout_amount = round( $total_sale_amount * ( $split_rate / 100.0 ), 2 );
		$dealer_profit_amount    = round( $total_sale_amount - $consignor_payout_amount, 2 );

		$inserted = $wpdb->insert(
			$table,
			array(
				'sale_record_id'          => $sale_record_id,
				'consignor_id'             => $consignor_id,
				'wc_order_id'              => isset( $payout_data['wc_order_id'] ) ? (int) $payout_data['wc_order_id'] : 0,
				'wc_product_id'            => isset( $payout_data['wc_product_id'] ) ? (int) $payout_data['wc_product_id'] : 0,
				'inventory_item_id'        => isset( $payout_data['inventory_item_id'] ) ? sanitize_text_field( $payout_data['inventory_item_id'] ) : '',
				'card_id'                  => isset( $payout_data['card_id'] ) ? sanitize_text_field( $payout_data['card_id'] ) : '',
				'card_name'                => isset( $payout_data['card_name'] ) ? sanitize_text_field( $payout_data['card_name'] ) : 'Unknown Card',
				'set_name'                 => isset( $payout_data['set_name'] ) ? sanitize_text_field( $payout_data['set_name'] ) : '',
				'card_number'              => isset( $payout_data['card_number'] ) ? sanitize_text_field( $payout_data['card_number'] ) : '',
				'quantity'                 => $quantity,
				'sale_price_per_unit'      => $sale_price_per_unit,
				'total_sale_amount'        => $total_sale_amount,
				'consignor_split_rate'     => $split_rate,
				'consignor_payout_amount'  => $consignor_payout_amount,
				'dealer_profit_amount'     => $dealer_profit_amount,
				'payout_status'            => isset( $payout_data['payout_status'] ) ? sanitize_text_field( $payout_data['payout_status'] ) : 'unpaid',
				'payout_date'              => isset( $payout_data['payout_date'] ) ? sanitize_text_field( $payout_data['payout_date'] ) : null,
				'payment_method'           => isset( $payout_data['payment_method'] ) ? sanitize_text_field( $payout_data['payment_method'] ) : ( $consignor['payout_method'] ?? 'Cash' ),
				'payment_reference'        => isset( $payout_data['payment_reference'] ) ? sanitize_text_field( $payout_data['payment_reference'] ) : '',
				'sale_source'              => isset( $payout_data['sale_source'] ) ? sanitize_text_field( $payout_data['sale_source'] ) : 'pos',
				'notes'                    => isset( $payout_data['notes'] ) ? sanitize_textarea_field( $payout_data['notes'] ) : '',
				'created_at'               => current_time( 'mysql' ),
			),
			array(
				'%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s',
				'%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s',
			)
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_payout_failed', __( 'Failed to record payout.', 'xophz-compass-card-vault' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve payouts list with filters.
	 *
	 * @param array $args Query filters.
	 * @return array
	 */
	public static function get_payouts( $args = array() ) {
		global $wpdb;
		$table = self::get_payouts_table();

		$defaults = array(
			'consignor_id'  => '',
			'payout_status' => '',
			'sale_source'   => '',
			'orderby'       => 'created_at',
			'order'         => 'DESC',
			'limit'         => 50,
			'offset'        => 0,
		);
		$r = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $r['consignor_id'] ) ) {
			$where[]  = 'consignor_id = %s';
			$params[] = sanitize_text_field( $r['consignor_id'] );
		}
		if ( ! empty( $r['payout_status'] ) ) {
			$where[]  = 'payout_status = %s';
			$params[] = sanitize_text_field( $r['payout_status'] );
		}
		if ( ! empty( $r['sale_source'] ) ) {
			$where[]  = 'sale_source = %s';
			$params[] = sanitize_text_field( $r['sale_source'] );
		}

		$allowed_order_by = array( 'id', 'created_at', 'total_sale_amount', 'consignor_payout_amount', 'payout_status' );
		$orderby = in_array( $r['orderby'], $allowed_order_by, true ) ? $r['orderby'] : 'created_at';
		$order   = strtoupper( $r['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}";

		if ( $r['limit'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $r['limit'], (int) $r['offset'] );
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Retrieve a single payout row by its sale_record_id.
	 *
	 * @param string $sale_record_id Sale record identifier.
	 * @return array|null
	 */
	public static function get_payout_by_sale_record_id( $sale_record_id ) {
		global $wpdb;
		$table = self::get_payouts_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE sale_record_id = %s LIMIT 1", sanitize_text_field( $sale_record_id ) );
		return $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Settle one unpaid payout: move it from 'unpaid' to 'paid'.
	 *
	 * Only the integer primary key is accepted, so a numeric-looking sale_record_id can
	 * never be mistaken for a row id. The update matches only a row that is still
	 * 'unpaid', and succeeds only when exactly one row changed: settling a row that is
	 * already paid, void, under clawback or missing returns false and leaves the first
	 * payment record (payout_date, payment_reference) untouched.
	 *
	 * @param int|string $payout_id Payout primary key (a positive integer or a string of digits).
	 * @param string     $payment_method Payment method used (e.g. Cash, Venmo, Zelle, Check).
	 * @param string     $payment_reference Check number, transfer ID, or receipt reference.
	 * @return bool True only when this call moved the row from unpaid to paid.
	 */
	public static function mark_payout_paid( $payout_id, $payment_method = 'Cash', $payment_reference = '' ) {
		global $wpdb;
		$table = self::get_payouts_table();

		$is_id = is_int( $payout_id ) || ( is_string( $payout_id ) && ctype_digit( $payout_id ) );
		if ( ! $is_id || (int) $payout_id <= 0 ) {
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'payout_status'     => 'paid',
				'payout_date'       => current_time( 'mysql' ),
				'payment_method'    => sanitize_text_field( $payment_method ),
				'payment_reference' => sanitize_text_field( $payment_reference ),
			),
			array(
				'id'            => (int) $payout_id,
				'payout_status' => 'unpaid',
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === $updated;
	}

	/**
	 * Calculate aggregate metrics for a specific consignor.
	 *
	 * @param string $consignor_id Consignor identifier.
	 * @return array
	 */
	public static function get_consignor_summary( $consignor_id ) {
		global $wpdb;
		$table_payouts = self::get_payouts_table();
		$consignor_id  = sanitize_text_field( $consignor_id );

		// Aggregations from payouts table. 'void' rows (sales reversed before settlement)
		// count toward nothing. 'clawback' rows hold negative deltas for refunds of sales
		// already paid out, so sales and profit totals are net of refunds and
		// clawback_due is what the consignor owes back.
		$stats_sql = $wpdb->prepare(
			"SELECT 
				COALESCE(SUM(CASE WHEN payout_status <> 'void' THEN quantity ELSE 0 END), 0) as total_sold_count,
				COALESCE(SUM(CASE WHEN payout_status <> 'void' THEN total_sale_amount ELSE 0.00 END), 0.00) as total_gross_sales,
				COALESCE(SUM(CASE WHEN payout_status = 'unpaid' THEN consignor_payout_amount ELSE 0.00 END), 0.00) as pending_payout_balance,
				COALESCE(SUM(CASE WHEN payout_status = 'paid' THEN consignor_payout_amount ELSE 0.00 END), 0.00) as total_paid_out,
				COALESCE(SUM(CASE WHEN payout_status = 'clawback' THEN -consignor_payout_amount ELSE 0.00 END), 0.00) as clawback_due,
				COALESCE(SUM(CASE WHEN payout_status <> 'void' THEN dealer_profit_amount ELSE 0.00 END), 0.00) as dealer_profit_total
			FROM {$table_payouts}
			WHERE consignor_id = %s",
			$consignor_id
		);
		$stats = $wpdb->get_row( $stats_sql, ARRAY_A );

		// Count active in-stock WooCommerce products for this consignor
		$active_items_count = 0;
		if ( class_exists( 'WooCommerce' ) ) {
			$query = new WP_Query( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => '_card_vault_consignor_id',
						'value'   => $consignor_id,
						'compare' => '=',
					),
					array(
						'key'     => '_stock_status',
						'value'   => 'instock',
						'compare' => '=',
					),
				),
			) );
			$active_items_count = (int) $query->found_posts;
		}

		return array(
			'consignor_id'           => $consignor_id,
			'total_sold_count'       => (int) ( $stats['total_sold_count'] ?? 0 ),
			'total_gross_sales'      => (float) ( $stats['total_gross_sales'] ?? 0.00 ),
			'pending_payout_balance' => (float) ( $stats['pending_payout_balance'] ?? 0.00 ),
			'total_paid_out'         => (float) ( $stats['total_paid_out'] ?? 0.00 ),
			'clawback_due'           => (float) ( $stats['clawback_due'] ?? 0.00 ),
			'dealer_profit_total'    => (float) ( $stats['dealer_profit_total'] ?? 0.00 ),
			'active_items_count'     => $active_items_count,
		);
	}

	/**
	 * Calculate master aggregate metrics across all consignors for the Dealer portal.
	 *
	 * @return array
	 */
	public static function get_dealer_aggregate_summary() {
		global $wpdb;
		$table_consignments = self::get_consignments_table();
		$table_payouts      = self::get_payouts_table();

		$consignors_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_consignments} WHERE status = 'active'" );

		// Same status rules as get_consignor_summary(): 'void' rows count toward nothing
		// but the record count, and 'clawback' rows are negative deltas.
		$stats_sql = "SELECT 
			COALESCE(COUNT(*), 0) as total_payout_records,
			COALESCE(SUM(CASE WHEN payout_status <> 'void' THEN quantity ELSE 0 END), 0) as total_items_sold,
			COALESCE(SUM(CASE WHEN payout_status <> 'void' THEN total_sale_amount ELSE 0.00 END), 0.00) as total_gross_sales,
			COALESCE(SUM(CASE WHEN payout_status = 'unpaid' THEN consignor_payout_amount ELSE 0.00 END), 0.00) as total_pending_liability,
			COALESCE(SUM(CASE WHEN payout_status = 'paid' THEN consignor_payout_amount ELSE 0.00 END), 0.00) as total_paid_out,
			COALESCE(SUM(CASE WHEN payout_status = 'clawback' THEN -consignor_payout_amount ELSE 0.00 END), 0.00) as total_clawback_due,
			COALESCE(SUM(CASE WHEN payout_status <> 'void' THEN dealer_profit_amount ELSE 0.00 END), 0.00) as total_dealer_profit
		FROM {$table_payouts}";
		$stats = $wpdb->get_row( $stats_sql, ARRAY_A );

		// Count all active Card Vault products
		$active_inventory_count = 0;
		if ( class_exists( 'WooCommerce' ) ) {
			$query = new WP_Query( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => '_card_vault_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_stock_status',
						'value'   => 'instock',
						'compare' => '=',
					),
				),
			) );
			$active_inventory_count = (int) $query->found_posts;
		}

		return array(
			'active_consignors_count'  => $consignors_count,
			'total_payout_records'     => (int) ( $stats['total_payout_records'] ?? 0 ),
			'total_items_sold'         => (int) ( $stats['total_items_sold'] ?? 0 ),
			'total_gross_sales'        => (float) ( $stats['total_gross_sales'] ?? 0.00 ),
			'total_pending_liability'  => (float) ( $stats['total_pending_liability'] ?? 0.00 ),
			'total_paid_out'           => (float) ( $stats['total_paid_out'] ?? 0.00 ),
			'total_clawback_due'       => (float) ( $stats['total_clawback_due'] ?? 0.00 ),
			'total_dealer_profit'      => (float) ( $stats['total_dealer_profit'] ?? 0.00 ),
			'active_inventory_count'   => $active_inventory_count,
		);
	}
}
