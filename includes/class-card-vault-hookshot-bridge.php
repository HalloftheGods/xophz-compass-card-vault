<?php
/**
 * Hookshot Webhook Automation Bridge for Card Vault.
 *
 * Integrates with Xophz Magic Hookshot to:
 * 1. Listen for catalog.updated events from cardvault.worldwidewebwork.com
 *    and trigger automated, zero-downtime database hydration.
 * 2. Dispatch outgoing events (e.g. sale completed, inventory adjusted)
 *    to notify the Central Hub or federated network partners.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Hookshot_Bridge {

	/**
	 * Initialize Hookshot listeners and action hooks.
	 */
	public static function init(): void {
		// Listen for generic incoming Hookshot events routed to card vault
		add_action( 'xophz_hookshot_incoming_event', array( __CLASS__, 'handle_incoming_event' ), 10, 2 );

		// Specific Hookshot custom action bridge trigger
		add_action( 'card_vault_handle_hub_catalog_update', array( __CLASS__, 'handle_catalog_update_event' ), 10, 1 );
	}

	/**
	 * Handle incoming webhook routed from Hookshot.
	 *
	 * @param string $event_name Event descriptor.
	 * @param array  $payload    Webhook payload.
	 */
	public static function handle_incoming_event( string $event_name, array $payload ): void {
		if ( $event_name === 'catalog.updated' || $event_name === 'card_vault.catalog_updated' ) {
			self::handle_catalog_update_event( $payload );
		}
	}

	/**
	 * Process a catalog update notification from the Central Hub.
	 *
	 * @param array $payload Webhook payload containing snapshot URL and metadata.
	 */
	public static function handle_catalog_update_event( array $payload ): void {
		$download_url = ! empty( $payload['download_url'] ) ? esc_url_raw( $payload['download_url'] ) : null;
		$checksum     = ! empty( $payload['checksum'] ) ? sanitize_text_field( $payload['checksum'] ) : null;

		// Perform asynchronous or immediate sync from Hub
		$result = Card_Vault_Catalog_Importer::sync_from_central_hub( $download_url );

		if ( class_exists( 'Xophz_Compass_Logger' ) ) {
			Xophz_Compass_Logger::info( 'Card Vault Catalog Hookshot sync completed', array(
				'success'     => $result['success'],
				'elapsed_sec' => $result['elapsed_sec'] ?? 0,
				'error'       => $result['error'] ?? null,
			) );
		}
	}

	/**
	 * Dispatch an outgoing Hookshot event to notify the ecosystem.
	 *
	 * @param string $event_type e.g. "catalog.updated" or "sale.completed".
	 * @param array  $data       Payload data.
	 */
	public static function dispatch_event( string $event_type, array $data = array() ): void {
		// If Hookshot's outgoing dispatcher is available, use it
		if ( class_exists( 'Hookshot_Dispatcher' ) && method_exists( 'Hookshot_Dispatcher', 'dispatch' ) ) {
			Hookshot_Dispatcher::dispatch( "card_vault.{$event_type}", $data );
			return;
		}

		// Fallback: Fire WordPress do_action for local listeners
		do_action( "card_vault_event_{$event_type}", $data );
	}
}
