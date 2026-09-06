<?php
/**
 * Fired during plugin activation.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Activator {

	/**
	 * Run database migrations, create custom roles, and configure initial options.
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Table: wp_xophz_vault_consignments
		$table_consignments = $wpdb->prefix . 'xophz_vault_consignments';
		$sql_consignments = "CREATE TABLE $table_consignments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consignor_id varchar(64) NOT NULL,
			wp_user_id bigint(20) unsigned DEFAULT 0,
			name varchar(191) NOT NULL,
			email varchar(191) DEFAULT '',
			phone varchar(50) DEFAULT '',
			default_split_rate decimal(5,2) NOT NULL DEFAULT 85.00,
			payout_method varchar(50) DEFAULT 'Cash',
			payout_handle varchar(191) DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			notes text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_consignor_id (consignor_id),
			KEY wp_user_id (wp_user_id),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql_consignments );

		// 2. Table: wp_xophz_vault_payouts
		$table_payouts = $wpdb->prefix . 'xophz_vault_payouts';
		$sql_payouts = "CREATE TABLE $table_payouts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sale_record_id varchar(64) NOT NULL,
			consignor_id varchar(64) NOT NULL,
			wc_order_id bigint(20) unsigned DEFAULT 0,
			wc_product_id bigint(20) unsigned DEFAULT 0,
			inventory_item_id varchar(64) DEFAULT '',
			card_id varchar(100) NOT NULL,
			card_name varchar(255) NOT NULL,
			set_name varchar(191) DEFAULT '',
			card_number varchar(50) DEFAULT '',
			quantity int(11) NOT NULL DEFAULT 1,
			sale_price_per_unit decimal(10,2) NOT NULL DEFAULT 0.00,
			total_sale_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			consignor_split_rate decimal(5,2) NOT NULL DEFAULT 85.00,
			consignor_payout_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			dealer_profit_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			payout_status varchar(20) NOT NULL DEFAULT 'unpaid',
			payout_date datetime DEFAULT NULL,
			payment_method varchar(50) DEFAULT '',
			payment_reference varchar(191) DEFAULT '',
			sale_source varchar(50) NOT NULL DEFAULT 'pos',
			notes text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_sale_record (sale_record_id),
			KEY consignor_id (consignor_id),
			KEY wc_order_id (wc_order_id),
			KEY wc_product_id (wc_product_id),
			KEY payout_status (payout_status),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_payouts );

		// 3. Table: wp_xophz_vault_collector_items
		$table_collector_items = $wpdb->prefix . 'xophz_vault_collector_items';
		$sql_collector_items = "CREATE TABLE $table_collector_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL,
			item_id varchar(64) NOT NULL,
			card_id varchar(100) NOT NULL,
			card_name varchar(255) NOT NULL,
			set_name varchar(191) DEFAULT '',
			card_number varchar(50) DEFAULT '',
			rarity varchar(50) DEFAULT '',
			condition_grade varchar(10) DEFAULT 'NM',
			quantity int(11) NOT NULL DEFAULT 1,
			is_foil tinyint(1) NOT NULL DEFAULT 0,
			acquired_price decimal(10,2) DEFAULT NULL,
			asking_price decimal(10,2) DEFAULT NULL,
			market_price decimal(10,2) DEFAULT 0.00,
			card_payload longtext DEFAULT NULL,
			user_notes text DEFAULT NULL,
			is_public_showcase tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_user_item (wp_user_id, item_id),
			KEY wp_user_id (wp_user_id),
			KEY card_id (card_id)
		) $charset_collate;";
		dbDelta( $sql_collector_items );

		// 4. Table: wp_xophz_vault_intake_batches
		$table_intake_batches = $wpdb->prefix . 'xophz_vault_intake_batches';
		$sql_intake_batches = "CREATE TABLE $table_intake_batches (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_code varchar(64) NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			consignor_id varchar(64) DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'submitted',
			dropoff_type varchar(30) NOT NULL DEFAULT 'counter',
			payout_preference varchar(30) NOT NULL DEFAULT 'consignment',
			total_items int(11) NOT NULL DEFAULT 0,
			total_claimed_value decimal(10,2) NOT NULL DEFAULT 0.00,
			appraised_value decimal(10,2) DEFAULT NULL,
			agreed_split_rate decimal(5,2) DEFAULT 80.00,
			notes text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_batch_code (batch_code),
			KEY wp_user_id (wp_user_id),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql_intake_batches );

		// 5. Table: wp_xophz_vault_intake_items
		$table_intake_items = $wpdb->prefix . 'xophz_vault_intake_items';
		$sql_intake_items = "CREATE TABLE $table_intake_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint(20) unsigned NOT NULL,
			batch_code varchar(64) NOT NULL,
			card_id varchar(100) NOT NULL,
			card_name varchar(255) NOT NULL,
			set_name varchar(191) DEFAULT '',
			card_number varchar(50) DEFAULT '',
			claimed_condition varchar(10) NOT NULL DEFAULT 'NM',
			appraised_condition varchar(10) DEFAULT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			is_foil tinyint(1) NOT NULL DEFAULT 0,
			claimed_value decimal(10,2) NOT NULL DEFAULT 0.00,
			appraised_value decimal(10,2) DEFAULT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			wc_product_id bigint(20) unsigned DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY batch_code (batch_code)
		) $charset_collate;";
		dbDelta( $sql_intake_items );

		// 6. Table: wp_xophz_vault_show_bids
		$table_show_bids = $wpdb->prefix . 'xophz_vault_show_bids';
		$sql_show_bids = "CREATE TABLE $table_show_bids (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bid_code varchar(64) NOT NULL,
			collector_user_id bigint(20) unsigned NOT NULL,
			showcase_slug varchar(191) NOT NULL,
			vendor_name varchar(191) NOT NULL,
			vendor_booth varchar(100) NOT NULL,
			vendor_phone varchar(50) NOT NULL,
			bid_type varchar(30) NOT NULL DEFAULT 'single_card',
			target_item_ids text DEFAULT NULL,
			target_card_summary text DEFAULT NULL,
			offer_type varchar(30) NOT NULL DEFAULT 'cash',
			offer_cash_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			offer_trade_description text DEFAULT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			counter_amount decimal(10,2) DEFAULT NULL,
			notes text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_bid_code (bid_code),
			KEY collector_user_id (collector_user_id),
			KEY showcase_slug (showcase_slug),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql_show_bids );

		// 7. Register roles and custom capabilities
		self::register_roles_and_capabilities();

		// 8. Flush rewrite rules for /card-vault endpoint
		if ( class_exists( 'Card_Vault_Public' ) ) {
			$public = new Card_Vault_Public();
			$public->register_rewrites();
		}
		flush_rewrite_rules();
	}

	/**
	 * Register Card Vault roles and grant capabilities.
	 */
	public static function register_roles_and_capabilities() {
		// Consignor role: Read only, with access to consignor dashboard
		add_role(
			'card_vault_consignor',
			__( 'Card Vault Consignor', 'xophz-compass-card-vault' ),
			array(
				'read'                     => true,
				'view_consignor_dashboard' => true,
			)
		);

		// Community Collector role
		add_role(
			'card_vault_collector',
			__( 'Card Vault Collector', 'xophz-compass-card-vault' ),
			array(
				'read'                 => true,
				'view_collector_vault' => true,
			)
		);

		// Grant capabilities to administrator
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'manage_card_vault' );
			$admin_role->add_cap( 'view_consignor_dashboard' );
			$admin_role->add_cap( 'view_collector_vault' );
		}

		// Grant capabilities to shop_manager (WooCommerce role) if present
		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( 'manage_card_vault' );
			$shop_manager->add_cap( 'view_consignor_dashboard' );
			$shop_manager->add_cap( 'view_collector_vault' );
		}
	}
}
