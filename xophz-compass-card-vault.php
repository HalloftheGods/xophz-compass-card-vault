<?php
/**
 * Plugin Name:       Xophz Card Vault
 * Description:       Offline-first Trade Desk, POS, optical grading, consignment accounting & WooCommerce product sync for My Card Vault.
 * Version:           26.9.6
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-card-vault
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_CARD_VAULT_VERSION', '26.9.6' );
define( 'XOPHZ_COMPASS_CARD_VAULT_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_COMPASS_CARD_VAULT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare High-Performance Order Storage (HPOS) compatibility with WooCommerce.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Core includes
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-activator.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-consignments.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-community.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-wc-sync.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-gemini.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-api.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'admin/class-card-vault-admin.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'public/class-card-vault-public.php';

// Ensure Core Helper Suite autoloader is active if running standalone
if ( ! class_exists( 'Xophz_Compass_Plugin_Base' ) ) {
	$autoloader = dirname( __DIR__ ) . '/xophz-compass/includes/core/class-compass-autoloader.php';
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
		Xophz_Compass_Autoloader::register();
	}
}

/**
 * Main Card Vault Plugin Class.
 * Extends Xophz_Compass_Plugin_Base and adopts Xophz_Compass_Hookable_Trait.
 */
class Card_Vault extends Xophz_Compass_Plugin_Base {

	/**
	 * Initialize plugin components and queued hooks.
	 */
	public function init(): void {
		// Initialize WooCommerce order hooks
		Card_Vault_WC_Sync::init();

		// Initialize public router and reverse proxy
		new Card_Vault_Public();

		// Initialize admin dashboard
		if ( is_admin() ) {
			new Card_Vault_Admin();
		}

		// Register REST API routes via Hookable Trait
		$this->add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Register with Compass Universal Admin Route & Bridge
		$this->add_action( 'xophz_compass_register_plugins', array( $this, 'register_compass_admin_bridge' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes(): void {
		$api = new Card_Vault_API();
		$api->register_routes();
	}

	/**
	 * Spark definition for YouMeOS Spark Registry (xophz_register_sparks).
	 *
	 * @return array<string, mixed>
	 */
	public function get_spark_definition(): ?array {
		$slug = get_option( 'xophz_compass_card_vault_custom_slug', 'card-vault' );
		return array(
			'id'          => 'card-vault',
			'title'       => 'Card Vault',
			'description' => 'Trade Desk & POS for Card Show Dealers',
			'icon'        => 'fal fa-cards',
			'color'       => '#62c9ff',
			'url'         => '/' . $slug,
			'category'    => 'pos',
			'type'        => 'webspark',
			'status'      => 'pi',
			'weight'      => 95,
			'active'      => true,
			'version'     => $this->version,
			'dimensions'  => array(
				'width'  => 1200,
				'height' => 800,
			),
		);
	}

	/**
	 * Register with Compass Universal Admin Route & Bridge.
	 */
	public function register_compass_admin_bridge(): void {
		if ( ! class_exists( 'Xophz_Compass' ) ) {
			return;
		}

		Xophz_Compass::register_admin_plugin( array(
			'slug'        => 'card-vault',
			'name'        => 'Card Vault',
			'title'       => 'Card Vault',
			'description' => 'Offline-first Trade Desk, Optical Grading, Consignment & WooCommerce Sync',
			'icon'        => plugins_url( 'icon.svg', $this->plugin_file ),
			'color'       => '#62c9ff',
			'category'    => 'Command Deck',
			'script_url'  => plugins_url( 'admin/js/card-vault-admin.js', $this->plugin_file ),
			'version'     => $this->version,
			'capability'  => 'manage_options',
			'navigation'  => array(
				array(
					'path'  => '',
					'title' => 'Dealer HQ',
					'icon'  => 'fal fa-tachometer-alt',
				),
				array(
					'path'  => 'consignors',
					'title' => 'Consignors',
					'icon'  => 'fal fa-users',
				),
				array(
					'path'  => 'payouts',
					'title' => 'Payouts Ledger',
					'icon'  => 'fal fa-file-invoice-dollar',
				),
				array(
					'path'  => 'settings',
					'title' => 'Settings & Sync',
					'icon'  => 'fal fa-sliders-h',
				),
			),
		) );
	}

	/**
	 * Deactivation callback.
	 * Flushes rewrite rules upon plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'Card_Vault_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Card_Vault', 'deactivate' ) );

/**
 * Begins execution of the plugin.
 */
function run_xophz_compass_card_vault() {
	$plugin = new Card_Vault( 'card-vault', XOPHZ_COMPASS_CARD_VAULT_VERSION, __FILE__ );
	$plugin->run();
}
add_action( 'plugins_loaded', 'run_xophz_compass_card_vault' );
