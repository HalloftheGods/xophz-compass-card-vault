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

		// 3. Register Consignor role and custom capabilities
		self::register_roles_and_capabilities();

		// 4. Flush rewrite rules for /card-vault endpoint
		if ( class_exists( 'Card_Vault_Public' ) ) {
			$public = new Card_Vault_Public();
			$public->register_rewrites();
		}
		flush_rewrite_rules();
	}

	/**
	 * Register the Card Vault Consignor role and grant dealer management capabilities.
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

		// Grant capabilities to administrator
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'manage_card_vault' );
			$admin_role->add_cap( 'view_consignor_dashboard' );
		}

		// Grant capabilities to shop_manager (WooCommerce role) if present
		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( 'manage_card_vault' );
			$shop_manager->add_cap( 'view_consignor_dashboard' );
		}
	}
}
