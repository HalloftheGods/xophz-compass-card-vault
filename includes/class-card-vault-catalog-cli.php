<?php
/**
 * WP-CLI Commands for Card Vault SQLite Catalog Management.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class Card_Vault_Catalog_CLI {

	/**
	 * Import a specific set/group directly from tcgcsv.com into SQLite.
	 *
	 * ## OPTIONS
	 *
	 * <category_id>
	 * : TCG Category ID (e.g. 3 for Pokemon).
	 *
	 * <group_id>
	 * : TCG Group ID (e.g. 3170 for Silver Tempest).
	 *
	 * [--group-name=<name>]
	 * : Optional human-readable set name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault sync-group 3 3170 --group-name="SWSH12: Silver Tempest"
	 */
	public function sync_group( $args, $assoc_args ) {
		$category_id = (int) $args[0];
		$group_id    = (int) $args[1];
		$group_name  = $assoc_args['group-name'] ?? null;

		WP_CLI::log( "Downloading and importing Category {$category_id}, Group {$group_id} from tcgcsv.com..." );

		$result = Card_Vault_Catalog_Importer::import_group_csv( $category_id, $group_id, $group_name );

		if ( ! $result['success'] ) {
			WP_CLI::error( 'Import failed: ' . ( $result['error'] ?? 'Unknown error' ) );
		}

		WP_CLI::success( "Successfully ingested {$result['imported_count']} cards in {$result['elapsed_sec']} seconds." );
	}

	/**
	 * Batch import Pokémon sets from tcgcsv.com with rate-limiting and resume capability.
	 *
	 * ## OPTIONS
	 *
	 * [--category=<id>]
	 * : TCG Category ID (defaults to 3 for Pokemon).
	 *
	 * [--batch-size=<num>]
	 * : Number of sets to import in this batch (default: 10).
	 *
	 * [--delay-ms=<ms>]
	 * : Milliseconds delay between sets to respect server resources (default: 750).
	 *
	 * [--limit=<num>]
	 * : Total sets to import (default: all un-synced).
	 *
	 * [--force]
	 * : Re-import already synced sets.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault sync-all --batch-size=10
	 *     wp card-vault sync-all --limit=25 --delay-ms=1000
	 */
	public function sync_all( $args, $assoc_args ) {
		$category_id = isset( $assoc_args['category'] ) ? (int) $assoc_args['category'] : 3;
		$batch_size  = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 10;
		$delay_ms    = isset( $assoc_args['delay-ms'] ) ? (int) $assoc_args['delay-ms'] : 750;
		$limit       = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null;
		$force       = ! empty( $assoc_args['force'] );

		WP_CLI::log( "Fetching groups list for category {$category_id} from tcgcsv.com..." );

		$progress = function( $gid, $gname, $idx, $total ) {
			WP_CLI::log( "[{$idx}/{$total}] Ingesting Group {$gid}: {$gname}..." );
		};

		$result = Card_Vault_Catalog_Importer::batch_sync_groups( $category_id, $batch_size, $delay_ms, $limit, $force, $progress );

		if ( ! $result['success'] ) {
			WP_CLI::error( 'Batch sync failed: ' . ( $result['error'] ?? 'Unknown error' ) );
		}

		WP_CLI::success( sprintf(
			'Batch complete! Ingested %d cards across %d sets in %.2f seconds (%d sets remaining).',
			$result['imported_cards'],
			$result['sets_processed'],
			$result['elapsed_sec'],
			$result['sets_remaining']
		) );
	}

	/**
	 * Synchronize catalog by downloading compressed snapshot from Central Hub.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<snapshot_url>]
	 * : Custom snapshot download URL (defaults to cardvault.worldwidewebwork.com).
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault sync-hub
	 */
	public function sync_hub( $args, $assoc_args ) {
		$url = $assoc_args['url'] ?? null;
		WP_CLI::log( 'Connecting to Central Hub (cardvault.worldwidewebwork.com)...' );

		$result = Card_Vault_Catalog_Importer::sync_from_central_hub( $url );

		if ( ! $result['success'] ) {
			WP_CLI::error( 'Sync failed: ' . ( $result['error'] ?? 'Unknown error' ) );
		}

		WP_CLI::success( "Successfully hydrated cards.db in {$result['elapsed_sec']} seconds via atomic swap." );
	}

	/**
	 * Search the local SQLite catalog using smart number and FTS5 resolution.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Search string (e.g. "Charizard 199/165", "004/102", "Lugia VSTAR", "TG01").
	 *
	 * [--limit=<num>]
	 * : Maximum number of results to display.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault search "Charizard 4/102"
	 *     wp card-vault search "199/165"
	 */
	public function search( $args, $assoc_args ) {
		$query = $args[0];
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 10;

		$start   = microtime( true );
		$results = Card_Vault_Catalog_DB::search_cards( $query, array(), $limit );
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		if ( empty( $results ) ) {
			WP_CLI::warning( "No cards found for query '{$query}' (Execution: {$elapsed} ms)." );
			return;
		}

		WP_CLI::log( "Found " . count( $results ) . " results in {$elapsed} ms:" );

		$formatted = array();
		foreach ( $results as $card ) {
			$formatted[] = array(
				'ID'     => $card['id'],
				'Name'   => $card['name'],
				'Set'    => $card['group_name'],
				'Number' => $card['raw_number'] ?: $card['clean_number'],
				'Rarity' => $card['rarity'],
				'Market' => '$' . number_format( (float) $card['market_price'], 2 ),
				'UPC'    => $card['upc'] ?: '-',
			);
		}

		WP_CLI\Utils\format_items( 'table', $formatted, array( 'ID', 'Name', 'Set', 'Number', 'Rarity', 'Market', 'UPC' ) );
	}

	/**
	 * Test barcode / SKU resolution (both manufacturer UPCs and single SKUs).
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Barcode string or CV-* single card SKU.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault barcode 820650860911
	 *     wp card-vault barcode CV-PKM-MEW-199-NM-4F2A
	 */
	public function barcode( $args, $assoc_args ) {
		$code = $args[0];
		$info = Card_Vault_SKU_Generator::identify_barcode( $code );

		WP_CLI::log( "Barcode identified as: [{$info['type']}]" );

		$card = Card_Vault_Catalog_DB::get_card_by_barcode( $code );
		if ( $card ) {
			WP_CLI::success( "Matched catalog card: {$card['name']} ({$card['group_name']} #{$card['raw_number']}) - Market: \${$card['market_price']}" );
			return;
		}

		WP_CLI::warning( "No catalog item found matching barcode '{$code}'." );
	}

	/**
	 * Display local SQLite database status and diagnostics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault status
	 */
	public function status( $args, $assoc_args ) {
		$status = Card_Vault_Catalog_DB::get_status();
		$last_checked = get_option( 'card_vault_catalog_last_checked' );
		$last_synced  = get_option( 'card_vault_catalog_last_synced' );
		$next_cron    = wp_next_scheduled( 'card_vault_catalog_check_cron' );
		$version      = get_option( 'card_vault_catalog_version', 'local' );

		WP_CLI::log( '=========================================' );
		WP_CLI::log( '  Card Vault SQLite Catalog Status' );
		WP_CLI::log( '=========================================' );
		WP_CLI::log( 'File Exists:   ' . ( $status['exists'] ? 'YES' : 'NO' ) );
		WP_CLI::log( 'Path:          ' . $status['path'] );
		WP_CLI::log( 'File Size:     ' . $status['size_mb'] . ' MB (' . number_format( $status['size_bytes'] ) . ' bytes)' );
		WP_CLI::log( 'WAL Size:      ' . number_format( $status['wal_bytes'] ) . ' bytes' );
		WP_CLI::log( 'Total Cards:   ' . number_format( $status['total_cards'] ) );
		WP_CLI::log( 'Last Updated:  ' . ( $status['last_updated'] ?: 'Never' ) );
		WP_CLI::log( 'Last Checked:  ' . ( $last_checked ? date( 'Y-m-d H:i:s', (int) $last_checked ) : 'Never' ) );
		WP_CLI::log( 'Last Synced:   ' . ( $last_synced ? date( 'Y-m-d H:i:s', (int) $last_synced ) : 'Never' ) );
		WP_CLI::log( 'Next 6h Cron:  ' . ( $next_cron ? date( 'Y-m-d H:i:s', (int) $next_cron ) : 'Not scheduled' ) );
		WP_CLI::log( 'DB Version:    ' . $version );
		WP_CLI::log( 'Hub Origin:    ' . Card_Vault_Catalog_Importer::HUB_DOMAIN );
		WP_CLI::log( '=========================================' );
	}

	/**
	 * Check Central Hub for updated catalog snapshots and optionally trigger sync.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Automatically sync if an update is available.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault check-updates
	 *     wp card-vault check-updates --sync
	 */
	public function check_updates( $args, $assoc_args ) {
		WP_CLI::log( 'Checking Central Hub for catalog snapshots...' );
		$check = Card_Vault_Catalog_Importer::check_for_updates();

		if ( $check['update_available'] ) {
			WP_CLI::warning( "Update available! Remote version: {$check['remote_version']} (Current: {$check['current_version']})" );
			if ( ! empty( $assoc_args['sync'] ) ) {
				WP_CLI::log( 'Triggering snapshot synchronization...' );
				$this->sync_hub( array(), array() );
			}
		} else {
			WP_CLI::success( "Catalog is up-to-date (Version: {$check['current_version']})." );
		}
	}

	/**
	 * Export current cards.db as compressed cards.db.gz for distribution.
	 *
	 * ## OPTIONS
	 *
	 * [--out=<path>]
	 * : Destination file path for compressed archive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp card-vault export-snapshot
	 */
	public function export_snapshot( $args, $assoc_args ) {
		$out = $assoc_args['out'] ?? null;
		WP_CLI::log( 'Optimizing and compressing cards.db...' );

		$res = Card_Vault_Catalog_Importer::export_compressed_snapshot( $out );
		if ( ! $res ) {
			WP_CLI::error( 'Export failed.' );
		}

		$size_mb = round( filesize( $res ) / ( 1024 * 1024 ), 2 );
		WP_CLI::success( "Exported compressed snapshot to {$res} ({$size_mb} MB)." );
	}
}

WP_CLI::add_command( 'card-vault', 'Card_Vault_Catalog_CLI' );
