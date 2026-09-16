<?php
/**
 * Hookshot Webhook Automation Bridge for Card Vault.
 *
 * Integrates with Xophz Magic Hookshot to:
 * 1. Register the card_vault_catalog_sync bridge.
 * 2. Listen for incoming CSV drops and snapshot updates, offloading heavy processing
 *    to Action Scheduler to guarantee non-blocking execution.
 * 3. Dispatch outgoing events (e.g. catalog.synced, sale.completed) to the ecosystem.
 *
 * Adheres to Chemical X Standards:
 * - Single-responsibility capsule, zero synthetic mock data, zero em dashes.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Hookshot_Bridge {

	const BRIDGE_ID = 'card_vault_catalog_sync';
	const ACTION_JOB = 'card_vault_process_csv_sync_job';

	/**
	 * Initialize Hookshot listeners and action hooks.
	 */
	public static function init(): void {
		// Register bridge with Hookshot
		add_action( 'xophz_hookshot_register_bridges', array( __CLASS__, 'register_bridge' ) );

		// Generic incoming hookshot event listener
		add_action( 'xophz_hookshot_incoming_event', array( __CLASS__, 'handle_incoming_event' ), 10, 2 );

		// Async Action Scheduler execution worker
		add_action( self::ACTION_JOB, array( __CLASS__, 'process_async_sync_job' ), 10, 1 );

		// Direct Hub catalog update hook fallback
		add_action( 'card_vault_handle_hub_catalog_update', array( __CLASS__, 'schedule_or_run_sync' ), 10, 1 );
	}

	/**
	 * Register Card Vault Catalog bridge definition in Hookshot.
	 */
	public static function register_bridge(): void {
		if ( class_exists( 'Hookshot_Bridges' ) && method_exists( 'Hookshot_Bridges', 'register' ) ) {
			Hookshot_Bridges::register( self::BRIDGE_ID, array(
				'name'        => 'Card Vault Catalog & Price Sync',
				'description' => 'Automated ingestion of CSV price feeds and SQLite snapshot hydration via Action Scheduler.',
				'icon'        => 'dashicons-database-import',
				'fields'      => array(
					'source'       => 'Data source: "tcgcsv", "pricecharting", or "snapshot"',
					'download_url' => 'Optional remote URL to download CSV or .db.gz archive',
					'category_id'  => 'Optional category ID (e.g. 3 for Pokemon)',
					'group_id'     => 'Optional group/set ID',
				),
				'handler'     => array( __CLASS__, 'schedule_or_run_sync' ),
			) );
		}
	}

	/**
	 * Handle incoming webhook routed from Hookshot.
	 *
	 * @param string $event_name Event descriptor.
	 * @param array  $payload    Webhook payload.
	 */
	public static function handle_incoming_event( string $event_name, array $payload ): void {
		$tracked_events = array(
			'catalog.updated',
			'card_vault.catalog_updated',
			'card_vault.csv_posted',
			'card_vault.pricecharting_ready',
		);

		if ( in_array( $event_name, $tracked_events, true ) ) {
			self::schedule_or_run_sync( $payload );
		}
	}

	/**
	 * Enqueue an asynchronous job via Action Scheduler or run immediately as fallback.
	 *
	 * @param array $payload Webhook payload.
	 */
	public static function schedule_or_run_sync( array $payload ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_JOB, array( $payload ), 'card-vault' );
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ACTION_JOB, array( $payload ), 'card-vault' );
			return;
		}

		// Fallback: Immediate execution if Action Scheduler is not installed
		self::process_async_sync_job( $payload );
	}

	/**
	 * Process the background CSV or snapshot sync job safely.
	 *
	 * @param array $payload Webhook payload.
	 */
	public static function process_async_sync_job( array $payload ): void {
		$source       = (string) ( $payload['source'] ?? '' );
		$download_url = ! empty( $payload['download_url'] ) ? esc_url_raw( $payload['download_url'] ) : null;
		$category_id  = isset( $payload['category_id'] ) ? (int) $payload['category_id'] : null;
		$group_id     = isset( $payload['group_id'] ) ? (int) $payload['group_id'] : null;

		$result = array( 'success' => false, 'imported_count' => 0 );

		// 1. PriceCharting CSV Ingestion
		if ( $source === 'pricecharting' && ! empty( $download_url ) ) {
			$temp_csv = wp_tempnam( 'pc_csv_' . time() );
			if ( $temp_csv ) {
				$resp = wp_remote_get( $download_url, array(
					'timeout'  => 120,
					'stream'   => true,
					'filename' => $temp_csv,
				) );

				if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
					$result = Card_Vault_Catalog_Importer::ingest_pricecharting_csv( $temp_csv );
				}
				@unlink( $temp_csv );
			}
		}
		// 2. Specific Set CSV Ingestion from tcgcsv
		elseif ( ! empty( $category_id ) && ! empty( $group_id ) ) {
			$result = Card_Vault_Catalog_Importer::import_group_csv( $category_id, $group_id );
		}
		// 3. Central Hub Database Snapshot Sync
		else {
			$result = Card_Vault_Catalog_Importer::sync_from_central_hub( $download_url );
		}

		// Log and dispatch outgoing event
		if ( class_exists( 'Xophz_Compass_Logger' ) ) {
			Xophz_Compass_Logger::info( 'Card Vault Hookshot sync completed', array(
				'source'      => $source,
				'success'     => $result['success'] ?? false,
				'elapsed_sec' => $result['elapsed_sec'] ?? 0,
				'error'       => $result['error'] ?? null,
			) );
		}

		self::dispatch_event( 'catalog_synced', array(
			'source'    => $source,
			'timestamp' => time(),
			'success'   => $result['success'] ?? false,
		) );
	}

	/**
	 * Dispatch an outgoing Hookshot event to notify the ecosystem.
	 *
	 * @param string $event_type e.g. "catalog_synced" or "sale_completed".
	 * @param array  $data       Payload data.
	 */
	public static function dispatch_event( string $event_type, array $data = array() ): void {
		if ( class_exists( 'Hookshot_Dispatcher' ) && method_exists( 'Hookshot_Dispatcher', 'dispatch' ) ) {
			Hookshot_Dispatcher::dispatch( "card_vault.{$event_type}", $data );
			return;
		}

		do_action( "card_vault_event_{$event_type}", $data );
	}
}
