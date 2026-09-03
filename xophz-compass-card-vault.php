<?php
/**
 * Plugin Name:       Xophz Card Vault
 * Description:       Offline-first Trade Desk, POS, optical grading, consignment accounting & WooCommerce product sync for My Card Vault.
 * Version:           26.9.2-1221
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-compass-card-vault
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_COMPASS_CARD_VAULT_VERSION', '26.9.2-1221' );
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
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-deactivator.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-consignments.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-wc-sync.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-gemini.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/class-card-vault-api.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'admin/class-card-vault-admin.php';
require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'public/class-card-vault-public.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'Card_Vault_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Card_Vault_Deactivator', 'deactivate' ) );

// Register with YouMeOS Spark Registry
add_filter( 'xophz_register_sparks', function( $sparks ) {
	$slug = get_option( 'xophz_compass_card_vault_custom_slug', 'card-vault' );
	$sparks['card-vault'] = array(
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
		'version'     => XOPHZ_COMPASS_CARD_VAULT_VERSION,
	);
	return $sparks;
} );

// Register Spark Window Manifest
add_filter( 'xophz_get_spark_manifest', function( $manifest, $spark_id ) {
	if ( 'card-vault' === $spark_id ) {
		return array(
			'id'          => 'card-vault',
			'title'       => 'Card Vault',
			'description' => 'Trade Desk & POS for Card Show Dealers',
			'icon'        => 'fal fa-cards',
			'color'       => '#62c9ff',
			'dimensions'  => array(
				'width'  => 1200,
				'height' => 800,
			),
		);
	}
	return $manifest;
}, 10, 2 );

// Register with Compass Universal Admin Route & Bridge
add_action( 'xophz_compass_register_plugins', function() {
	if ( ! class_exists( 'Xophz_Compass' ) ) {
		return;
	}

	Xophz_Compass::register_admin_plugin( array(
		'slug'        => 'card-vault',
		'name'        => 'Card Vault',
		'title'       => 'Card Vault',
		'description' => 'Offline-first Trade Desk, Optical Grading, Consignment & WooCommerce Sync',
		'icon'        => plugins_url( 'icon.svg', __FILE__ ),
		'color'       => '#62c9ff',
		'category'    => 'Command Deck',
		'script_url'  => plugins_url( 'admin/js/card-vault-admin.js', __FILE__ ),
		'version'     => XOPHZ_COMPASS_CARD_VAULT_VERSION,
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
} );

/**
 * Initialize Card Vault components and hooks.
 */
function xophz_compass_card_vault_init() {
	// Initialize WooCommerce order hooks
	Card_Vault_WC_Sync::init();

	// Initialize public router and reverse proxy
	new Card_Vault_Public();

	// Initialize admin dashboard
	if ( is_admin() ) {
		new Card_Vault_Admin();
	}

	// Register REST API routes
	add_action( 'rest_api_init', function() {
		$api = new Card_Vault_API();
		$api->register_routes();
	} );
}
add_action( 'plugins_loaded', 'xophz_compass_card_vault_init' );

/**
 * Add settings shortcut link on the WordPress Plugins management page.
 *
 * @param array $links Array of plugin action links.
 * @return array
 */
function xophz_compass_card_vault_action_links( $links ) {
	foreach ( $links as $link ) {
		if ( stripos( $link, '>Settings<' ) !== false ) {
			return $links;
		}
	}
	$settings_link = '<a href="options-general.php?page=xophz-compass-card-vault">' . esc_html__( 'Settings', 'xophz-compass-card-vault' ) . '</a>';
	$new_links     = array( 'settings' => $settings_link );
	foreach ( $links as $key => $value ) {
		$new_links[ $key ] = $value;
	}
	return $new_links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_compass_card_vault_action_links' );
