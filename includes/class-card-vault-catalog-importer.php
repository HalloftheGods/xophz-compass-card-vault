<?php
/**
 * TCG Catalog Importer and Hub Synchronization Engine.
 *
 * Supports two operational modes:
 * 1. Hub Mode (cardvault.worldwidewebwork.com): Ingests raw CSV dumps from
 *    tcgcsv.com, normalizes numbers, builds cards.db, and exports compressed snapshots.
 * 2. BlackBox Client Mode: Downloads compressed cards.db.gz from the Hub,
 *    decompresses to a temporary file, and performs an atomic swap with zero downtime.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Catalog_Importer {

	const HUB_DOMAIN = 'cardvault.worldwidewebwork.com';
	const SNAPSHOT_ENDPOINT = 'https://cardvault.worldwidewebwork.com/snapshots/cards.db.gz';
	const VERSION_ENDPOINT = 'https://cardvault.worldwidewebwork.com/snapshots/version.json';
	const DEFAULT_CATEGORY_ID = 3; // Pokemon

	/**
	 * Initialize catalog hooks and cron schedules.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( 'card_vault_catalog_check_cron', array( __CLASS__, 'handle_cron_check_and_update' ) );

		if ( ! wp_next_scheduled( 'card_vault_catalog_check_cron' ) ) {
			wp_schedule_event( time(), 'six_hours', 'card_vault_catalog_check_cron' );
		}
	}

	/**
	 * Register 6-hour cron interval.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['six_hours'] ) ) {
			$schedules['six_hours'] = array(
				'interval' => 21600,
				'display'  => 'Every 6 Hours',
			);
		}
		return $schedules;
	}

	/**
	 * Check Central Hub for updated catalog snapshots.
	 *
	 * @return array{update_available: bool, current_version: string, remote_version: string, last_checked: int}
	 */
	public static function check_for_updates(): array {
		$current_status   = Card_Vault_Catalog_DB::get_status();
		$current_version  = (string) get_option( 'card_vault_catalog_version', '' );
		$remote_version   = '';
		$remote_epoch     = 0;
		$update_available = false;

		// 1. Query version.json manifest from Central Hub
		$response = wp_remote_get( self::VERSION_ENDPOINT, array(
			'timeout'   => 10,
			'sslverify' => true,
		) );

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) ) {
				$remote_version = (string) ( $body['version'] ?? ( $body['timestamp'] ?? '' ) );
				$remote_epoch   = (int) ( $body['timestamp'] ?? 0 );
			}
		} else {
			// Fallback: Check HTTP HEAD Last-Modified / ETag on snapshot gz
			$head = wp_remote_head( self::SNAPSHOT_ENDPOINT, array( 'timeout' => 10 ) );
			if ( ! is_wp_error( $head ) && wp_remote_retrieve_response_code( $head ) === 200 ) {
				$etag     = wp_remote_retrieve_header( $head, 'etag' );
				$last_mod = wp_remote_retrieve_header( $head, 'last-modified' );
				$remote_version = ! empty( $etag ) ? trim( (string) $etag, '"' ) : ( $last_mod ? (string) strtotime( (string) $last_mod ) : '' );
				$remote_epoch   = $last_mod ? (int) strtotime( (string) $last_mod ) : 0;
			}
		}

		$has_db = ! empty( $current_status['exists'] ) && ( $current_status['total_cards'] ?? 0 ) > 0;
		if ( ! $has_db ) {
			$update_available = true;
		} elseif ( ! empty( $remote_version ) && $remote_version !== $current_version ) {
			$update_available = true;
		} elseif ( $remote_epoch > 0 && ! empty( $current_status['last_updated'] ) ) {
			$local_epoch = strtotime( (string) $current_status['last_updated'] );
			if ( $remote_epoch > $local_epoch ) {
				$update_available = true;
			}
		}

		$now = time();
		update_option( 'card_vault_catalog_last_checked', $now );
		if ( ! empty( $remote_version ) ) {
			update_option( 'card_vault_catalog_remote_version', $remote_version );
		}

		return array(
			'update_available' => $update_available,
			'current_version'  => $current_version,
			'remote_version'   => $remote_version,
			'last_checked'     => $now,
		);
	}

	/**
	 * Cron worker callback for the 6-hour check (Option 3 Self-Hosted Failsafe).
	 */
	public static function handle_cron_check_and_update(): void {
		$check = self::check_for_updates();
		if ( $check['update_available'] ) {
			if ( class_exists( 'Card_Vault_Hookshot_Bridge' ) ) {
				Card_Vault_Hookshot_Bridge::schedule_or_run_sync( array(
					'source'       => 'snapshot',
					'download_url' => self::SNAPSHOT_ENDPOINT,
				) );
			} else {
				$result = self::sync_from_central_hub();
				if ( $result['success'] ) {
					update_option( 'card_vault_catalog_last_synced', time() );
					if ( ! empty( $check['remote_version'] ) ) {
						update_option( 'card_vault_catalog_version', $check['remote_version'] );
					}
				}
			}
		}
	}

	/**
	 * Fetch available groups/sets list for a category from tcgcsv.com (cached for 6 hours).
	 *
	 * @param int  $category_id Category ID (3 = Pokemon).
	 * @param bool $force_refresh Force fresh fetch bypass cache.
	 * @return array List of available groups.
	 */
	public static function get_available_groups( int $category_id = 3, bool $force_refresh = false ): array {
		$cache_key = "card_vault_tcg_groups_{$category_id}";
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$url = "https://tcgcsv.com/tcgplayer/{$category_id}/groups";
		$response = wp_remote_get( $url, array(
			'timeout'    => 20,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (CardVaultCatalogSync/1.0)',
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$groups = $body['results'] ?? ( is_array( $body ) ? $body : array() );

		if ( ! empty( $groups ) ) {
			set_transient( $cache_key, $groups, 6 * HOUR_IN_SECONDS );
		}

		return $groups;
	}

	/**
	 * Batch import sets from tcgcsv.com with rate-limiting and resume support.
	 *
	 * @param int           $category_id Category ID (3 = Pokemon).
	 * @param int           $batch_size Max sets to import in this run (default: 10).
	 * @param int           $delay_ms Milliseconds delay between sets (default: 750).
	 * @param int|null      $limit Total maximum sets to process (null = all).
	 * @param bool          $force Re-import already synced sets.
	 * @param callable|null $progress_callback Optional callback fn(group_id, group_name, index, total).
	 * @return array Batch sync summary.
	 */
	public static function batch_sync_groups( int $category_id = 3, int $batch_size = 10, int $delay_ms = 750, ?int $limit = null, bool $force = false, ?callable $progress_callback = null ): array {
		$start_time = microtime( true );
		$groups = self::get_available_groups( $category_id );

		if ( empty( $groups ) ) {
			return array(
				'success'     => false,
				'imported'    => 0,
				'sets_count'  => 0,
				'elapsed_sec' => 0,
				'error'       => 'Could not fetch groups list from tcgcsv.com',
			);
		}

		// Retrieve currently synced groups from SQLite
		$synced = Card_Vault_Catalog_DB::get_synced_groups();
		$synced_ids = array_flip( array_column( $synced, 'group_id' ) );

		// Filter out groups: prioritize non-supplemental, un-synced sets
		$queue = array();
		foreach ( $groups as $g ) {
			$gid = (int) ( $g['groupId'] ?? 0 );
			if ( $gid <= 0 ) {
				continue;
			}
			if ( ! $force && isset( $synced_ids[ $gid ] ) ) {
				continue; // Skip already synced
			}
			$queue[] = $g;
		}

		$max_to_process = ! empty( $limit ) ? min( $limit, count( $queue ) ) : min( $batch_size, count( $queue ) );
		$slice = array_slice( $queue, 0, $max_to_process );

		$imported_cards = 0;
		$processed_sets = array();
		$errors = array();

		foreach ( $slice as $idx => $group ) {
			$gid = (int) ( $group['groupId'] ?? 0 );
			$gname = (string) ( $group['name'] ?? "Set #{$gid}" );

			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, $gid, $gname, $idx + 1, count( $slice ) );
			}

			$res = self::import_group_csv( $category_id, $gid, $gname );
			if ( $res['success'] ) {
				$imported_cards += $res['imported_count'];
				$processed_sets[] = array(
					'group_id'   => $gid,
					'name'       => $gname,
					'card_count' => $res['imported_count'],
					'status'     => 'success',
				);
			} else {
				$errors[] = array(
					'group_id' => $gid,
					'name'     => $gname,
					'error'    => $res['error'] ?? 'Unknown error',
				);
			}

			// Polite throttle sleep between downloads
			if ( $idx < count( $slice ) - 1 && $delay_ms > 0 ) {
				usleep( $delay_ms * 1000 );
			}
		}

		$elapsed = round( microtime( true ) - $start_time, 2 );

		return array(
			'success'          => count( $errors ) === 0 || count( $processed_sets ) > 0,
			'imported_cards'   => $imported_cards,
			'sets_processed'   => count( $processed_sets ),
			'sets_remaining'   => max( 0, count( $queue ) - count( $slice ) ),
			'total_sets'       => count( $groups ),
			'synced_sets'      => count( Card_Vault_Catalog_DB::get_synced_groups() ),
			'processed_groups' => $processed_sets,
			'errors'           => $errors,
			'elapsed_sec'      => $elapsed,
		);
	}

	/**
	 * Import a group's ProductsAndPrices.csv directly into the SQLite database.
	 *
	 * @param int         $category_id Category ID (3 = Pokemon).
	 * @param int         $group_id    Group/Set ID (e.g. 3170 for Silver Tempest).
	 * @param string|null $group_name  Optional set name.
	 * @param string|null $category_name Optional category name.
	 * @return array{success: bool, imported_count: int, elapsed_sec: float, error?: string}
	 */
	public static function import_group_csv( int $category_id, int $group_id, ?string $group_name = null, ?string $category_name = null ): array {
		Card_Vault_Catalog_DB::ensure_database();
		$start_time = microtime( true );

		$cat_name = ! empty( $category_name ) ? $category_name : ( $category_id === 3 ? 'Pokemon' : 'TCG' );
		$grp_name = ! empty( $group_name ) ? $group_name : "Set #{$group_id}";

		$url = "https://tcgcsv.com/tcgplayer/{$category_id}/{$group_id}/ProductsAndPrices.csv";

		// Download to local temp file
		$temp_file = wp_tempnam( 'tcg_csv_' . $group_id );
		if ( ! $temp_file ) {
			return array( 'success' => false, 'imported_count' => 0, 'elapsed_sec' => 0, 'error' => 'Unable to create temp file.' );
		}

		$response = wp_remote_get( $url, array(
			'timeout'    => 60,
			'stream'     => true,
			'filename'   => $temp_file,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (CardVaultCatalogSync/1.0)',
		) );

		if ( is_wp_error( $response ) ) {
			@unlink( $temp_file );
			return array(
				'success'        => false,
				'imported_count' => 0,
				'elapsed_sec'    => microtime( true ) - $start_time,
				'error'          => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			@unlink( $temp_file );
			return array(
				'success'        => false,
				'imported_count' => 0,
				'elapsed_sec'    => microtime( true ) - $start_time,
				'error'          => "HTTP request failed with status {$status_code}",
			);
		}

		// Ingest from local temp file
		$result = self::ingest_csv_file( $temp_file, $category_id, $group_id, $cat_name, $grp_name );
		@unlink( $temp_file );

		$result['elapsed_sec'] = round( microtime( true ) - $start_time, 2 );
		return $result;
	}

	/**
	 * Ingest rows from a local CSV file into SQLite.
	 *
	 * @param string $csv_path Absolute path to local CSV.
	 * @param int    $category_id
	 * @param int    $group_id
	 * @param string $category_name
	 * @param string $group_name
	 * @return array{success: bool, imported_count: int, error?: string}
	 */
	public static function ingest_csv_file( string $csv_path, int $category_id, int $group_id, string $category_name, string $group_name ): array {
		if ( ! file_exists( $csv_path ) || ! is_readable( $csv_path ) ) {
			return array( 'success' => false, 'imported_count' => 0, 'error' => 'CSV file unreadable.' );
		}

		$handle = fopen( $csv_path, 'r' );
		if ( ! $handle ) {
			return array( 'success' => false, 'imported_count' => 0, 'error' => 'Failed to open CSV file.' );
		}

		$header = fgetcsv( $handle );
		if ( ! $header || ! is_array( $header ) ) {
			fclose( $handle );
			return array( 'success' => false, 'imported_count' => 0, 'error' => 'Invalid or empty CSV header.' );
		}

		// Map column names to indices
		$cols = array_flip( array_map( 'trim', $header ) );

		$pdo = Card_Vault_Catalog_DB::get_connection();
		$sql = '
			INSERT OR REPLACE INTO cards (
				id, tcgplayer_id, category_id, category_name, group_id, group_name,
				name, clean_name, sub_type_name,
				raw_number, clean_number, numeric_number, number_prefix, total_set_number, number_variants,
				rarity, card_type, stage_or_subtype, hp, card_text, upc,
				market_price, low_price, mid_price, high_price, direct_low_price,
				updated_at, image_url, tcgplayer_url
			) VALUES (
				:id, :tcgplayer_id, :category_id, :category_name, :group_id, :group_name,
				:name, :clean_name, :sub_type_name,
				:raw_number, :clean_number, :numeric_number, :number_prefix, :total_set_number, :number_variants,
				:rarity, :card_type, :stage_or_subtype, :hp, :card_text, :upc,
				:market_price, :low_price, :mid_price, :high_price, :direct_low_price,
				:updated_at, :image_url, :tcgplayer_url
			)
		';

		$stmt = $pdo->prepare( $sql );
		$now = time();
		$count = 0;

		$pdo->beginTransaction();

		try {
			while ( ( $row = fgetcsv( $handle ) ) !== false ) {
				$pid = isset( $cols['productId'] ) ? (int) ( $row[ $cols['productId'] ] ?? 0 ) : 0;
				if ( $pid <= 0 ) {
					continue;
				}

				$name = isset( $cols['name'] ) ? trim( (string) ( $row[ $cols['name'] ] ?? '' ) ) : '';
				if ( empty( $name ) ) {
					continue;
				}

				$clean_name = isset( $cols['cleanName'] ) ? strtolower( trim( (string) $row[ $cols['cleanName'] ] ) ) : strtolower( $name );
				$raw_number = isset( $cols['extNumber'] ) ? trim( (string) ( $row[ $cols['extNumber'] ] ?? '' ) ) : '';

				// Smart Number Normalization
				$norm = Card_Vault_Number_Normalizer::normalize( $raw_number );

				$rarity      = isset( $cols['extRarity'] ) ? trim( (string) ( $row[ $cols['extRarity'] ] ?? '' ) ) : '';
				$card_type   = isset( $cols['extCardType'] ) ? trim( (string) ( $row[ $cols['extCardType'] ] ?? '' ) ) : '';
				$stage       = isset( $cols['extStage'] ) ? trim( (string) ( $row[ $cols['extStage'] ] ?? '' ) ) : '';
				$hp_raw      = isset( $cols['extHP'] ) ? preg_replace( '/\D/', '', (string) ( $row[ $cols['extHP'] ] ?? '' ) ) : '';
				$hp          = $hp_raw !== '' ? (int) $hp_raw : null;
				$card_text   = isset( $cols['extCardText'] ) ? trim( (string) ( $row[ $cols['extCardText'] ] ?? '' ) ) : '';
				$upc         = isset( $cols['extUPC'] ) ? trim( (string) ( $row[ $cols['extUPC'] ] ?? '' ) ) : null;
				$subtype     = isset( $cols['subTypeName'] ) ? trim( (string) ( $row[ $cols['subTypeName'] ] ?? 'Normal' ) ) : 'Normal';

				$market_price = isset( $cols['marketPrice'] ) && is_numeric( $row[ $cols['marketPrice'] ] ) ? (float) $row[ $cols['marketPrice'] ] : 0.0;
				$low_price    = isset( $cols['lowPrice'] ) && is_numeric( $row[ $cols['lowPrice'] ] ) ? (float) $row[ $cols['lowPrice'] ] : 0.0;
				$mid_price    = isset( $cols['midPrice'] ) && is_numeric( $row[ $cols['midPrice'] ] ) ? (float) $row[ $cols['midPrice'] ] : 0.0;
				$high_price   = isset( $cols['highPrice'] ) && is_numeric( $row[ $cols['highPrice'] ] ) ? (float) $row[ $cols['highPrice'] ] : 0.0;
				$direct_low   = isset( $cols['directLowPrice'] ) && is_numeric( $row[ $cols['directLowPrice'] ] ) ? (float) $row[ $cols['directLowPrice'] ] : null;

				$image_url    = isset( $cols['imageUrl'] ) ? trim( (string) ( $row[ $cols['imageUrl'] ] ?? '' ) ) : '';
				$tcg_url      = isset( $cols['url'] ) ? trim( (string) ( $row[ $cols['url'] ] ?? '' ) ) : '';

				$stmt->execute( array(
					':id'               => "tcg-{$pid}",
					':tcgplayer_id'     => $pid,
					':category_id'      => $category_id,
					':category_name'    => $category_name,
					':group_id'         => $group_id,
					':group_name'       => $group_name,
					':name'             => $name,
					':clean_name'       => $clean_name,
					':sub_type_name'    => $subtype,
					':raw_number'       => $norm['raw_number'],
					':clean_number'     => $norm['clean_number'],
					':numeric_number'   => $norm['numeric_number'],
					':number_prefix'    => $norm['number_prefix'],
					':total_set_number' => $norm['total_set_number'],
					':number_variants'  => $norm['number_variants'],
					':rarity'           => $rarity,
					':card_type'        => $card_type,
					':stage_or_subtype' => $stage,
					':hp'               => $hp,
					':card_text'        => $card_text,
					':upc'              => ! empty( $upc ) ? $upc : null,
					':market_price'     => $market_price,
					':low_price'        => $low_price,
					':mid_price'        => $mid_price,
					':high_price'       => $high_price,
					':direct_low_price' => $direct_low,
					':updated_at'       => $now,
					':image_url'        => $image_url,
					':tcgplayer_url'    => $tcg_url,
				) );

				$count++;
				$history_snapshots[] = array(
					'card_id'    => "tcg-{$pid}",
					'raw_market' => $market_price,
					'raw_low'    => $low_price,
					'raw_mid'    => $mid_price,
					'raw_high'   => $high_price,
					'source'     => 'tcgcsv',
				);
			}

			if ( ! empty( $history_snapshots ) ) {
				Card_Vault_Price_History::record_batch_snapshots( $pdo, $history_snapshots );
			}

			$pdo->commit();
			fclose( $handle );

			return array( 'success' => true, 'imported_count' => $count );
		} catch ( Exception $e ) {
			$pdo->rollBack();
			fclose( $handle );
			return array( 'success' => false, 'imported_count' => $count, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Ingest PriceCharting CSV export to populate graded slab comps and price history.
	 *
	 * Performs composite matching (set, number, variant) to prevent comp mismatches.
	 *
	 * @param string $csv_path Absolute path to PriceCharting CSV.
	 * @return array{success: bool, matched_count: int, elapsed_sec: float, error?: string}
	 */
	public static function ingest_pricecharting_csv( string $csv_path ): array {
		if ( ! file_exists( $csv_path ) || ! is_readable( $csv_path ) ) {
			return array( 'success' => false, 'matched_count' => 0, 'elapsed_sec' => 0, 'error' => 'CSV file unreadable.' );
		}

		$handle = fopen( $csv_path, 'r' );
		if ( ! $handle ) {
			return array( 'success' => false, 'matched_count' => 0, 'elapsed_sec' => 0, 'error' => 'Failed to open CSV file.' );
		}

		$header = fgetcsv( $handle );
		if ( ! $header || ! is_array( $header ) ) {
			fclose( $handle );
			return array( 'success' => false, 'matched_count' => 0, 'elapsed_sec' => 0, 'error' => 'Invalid or empty CSV header.' );
		}

		// Normalize column keys
		$cols = array();
		foreach ( $header as $idx => $col_name ) {
			$norm_key = strtolower( trim( str_replace( array( '-', '_', ' ' ), '', $col_name ) ) );
			$cols[ $norm_key ] = $idx;
		}

		$idx_product = $cols['productname'] ?? ( $cols['name'] ?? null );
		$idx_console = $cols['consolename'] ?? ( $cols['set'] ?? null );
		$idx_loose   = $cols['looseprice'] ?? ( $cols['priceinpennies'] ?? ( $cols['ungradedprice'] ?? null ) );
		$idx_psa10   = $cols['psa10price'] ?? ( $cols['gradedprice'] ?? ( $cols['gemmintprice'] ?? null ) );
		$idx_psa9    = $cols['psa9price'] ?? ( $cols['mintprice'] ?? null );

		if ( $idx_product === null ) {
			fclose( $handle );
			return array( 'success' => false, 'matched_count' => 0, 'elapsed_sec' => 0, 'error' => 'Missing product-name column in PriceCharting CSV.' );
		}

		Card_Vault_Catalog_DB::ensure_database();
		$pdo = Card_Vault_Catalog_DB::get_connection();
		$start_time = microtime( true );
		$now = time();
		$matched_count = 0;
		$history_snapshots = array();

		$update_stmt = $pdo->prepare( '
			UPDATE cards SET
				psa9_price = CASE WHEN :psa9 > 0 THEN :psa9 ELSE psa9_price END,
				psa10_price = CASE WHEN :psa10 > 0 THEN :psa10 ELSE psa10_price END,
				pricing_source = "pricecharting",
				updated_at = :now
			WHERE id = :id
		' );

		$lookup_num_stmt = $pdo->prepare( '
			SELECT id, clean_name, group_name FROM cards
			WHERE clean_number = :clean_num AND (group_name LIKE :set_wild OR :set_exact = "")
			LIMIT 5
		' );

		$pdo->beginTransaction();

		try {
			while ( ( $row = fgetcsv( $handle ) ) !== false ) {
				$raw_product = isset( $row[ $idx_product ] ) ? trim( (string) $row[ $idx_product ] ) : '';
				if ( empty( $raw_product ) ) {
					continue;
				}

				$raw_console = ( $idx_console !== null && isset( $row[ $idx_console ] ) ) ? trim( (string) $row[ $idx_console ] ) : '';
				$clean_set   = preg_replace( '/^pokemon\s+/i', '', $raw_console );

				$card_number = '';
				$variant = '';
				$card_name = $raw_product;

				if ( preg_match( '/#([A-Za-z0-9\/-]+)/', $raw_product, $m ) ) {
					$card_number = $m[1];
					$card_name = trim( str_replace( $m[0], '', $card_name ) );
				}

				if ( preg_match( '/\[(.*?)\]/', $raw_product, $m ) ) {
					$variant = $m[1];
					$card_name = trim( str_replace( $m[0], '', $card_name ) );
				}

				$card_name = preg_replace( '/\s+/', ' ', trim( $card_name ) );
				$clean_num = ltrim( explode( '/', $card_number )[0], '0' );

				$raw_loose_val = ( $idx_loose !== null && isset( $row[ $idx_loose ] ) ) ? (float) preg_replace( '/[^\d.]/', '', (string) $row[ $idx_loose ] ) : 0.0;
				$raw_psa10_val = ( $idx_psa10 !== null && isset( $row[ $idx_psa10 ] ) ) ? (float) preg_replace( '/[^\d.]/', '', (string) $row[ $idx_psa10 ] ) : 0.0;
				$raw_psa9_val  = ( $idx_psa9 !== null && isset( $row[ $idx_psa9 ] ) ) ? (float) preg_replace( '/[^\d.]/', '', (string) $row[ $idx_psa9 ] ) : 0.0;

				$psa10 = $raw_psa10_val > 500 && strpos( strtolower( $header[ $idx_psa10 ] ?? '' ), 'pennies' ) !== false ? $raw_psa10_val / 100 : $raw_psa10_val;
				$psa9  = $raw_psa9_val > 500 && strpos( strtolower( $header[ $idx_psa9 ] ?? '' ), 'pennies' ) !== false ? $raw_psa9_val / 100 : $raw_psa9_val;
				$loose = $raw_loose_val > 500 && strpos( strtolower( $header[ $idx_loose ] ?? '' ), 'pennies' ) !== false ? $raw_loose_val / 100 : $raw_loose_val;

				if ( $psa10 <= 0 && $psa9 <= 0 ) {
					continue;
				}

				$matched_id = null;
				if ( ! empty( $clean_num ) ) {
					$lookup_num_stmt->execute( array(
						':clean_num' => $clean_num,
						':set_wild'  => "%{$clean_set}%",
						':set_exact' => $clean_set,
					) );
					$candidates = $lookup_num_stmt->fetchAll( PDO::FETCH_ASSOC );

					if ( count( $candidates ) === 1 ) {
						$matched_id = $candidates[0]['id'];
					} elseif ( count( $candidates ) > 1 ) {
						$lower_name = strtolower( $card_name );
						foreach ( $candidates as $cand ) {
							if ( strpos( $cand['clean_name'], $lower_name ) !== false || strpos( $lower_name, $cand['clean_name'] ) !== false ) {
								$matched_id = $cand['id'];
								break;
							}
						}
					}
				}

				if ( $matched_id ) {
					$update_stmt->execute( array(
						':id'    => $matched_id,
						':psa9'  => $psa9,
						':psa10' => $psa10,
						':now'   => $now,
					) );

					$history_snapshots[] = array(
						'card_id'    => $matched_id,
						'raw_market' => $loose,
						'psa_9'      => $psa9,
						'psa_10'     => $psa10,
						'source'     => 'pricecharting',
					);

					$matched_count++;
				}
			}

			if ( ! empty( $history_snapshots ) ) {
				Card_Vault_Price_History::record_batch_snapshots( $pdo, $history_snapshots );
			}

			$pdo->commit();
			fclose( $handle );

			return array(
				'success'       => true,
				'matched_count' => $matched_count,
				'elapsed_sec'   => round( microtime( true ) - $start_time, 2 ),
			);
		} catch ( Exception $e ) {
			$pdo->rollBack();
			fclose( $handle );
			return array(
				'success'       => false,
				'matched_count' => $matched_count,
				'elapsed_sec'   => round( microtime( true ) - $start_time, 2 ),
				'error'         => $e->getMessage(),
			);
		}
	}

	/**
	 * Pull compressed snapshot from the Central Hub and perform an atomic swap.
	 *
	 * @param string|null $snapshot_url Optional custom snapshot endpoint.
	 * @return array{success: bool, elapsed_sec: float, error?: string}
	 */
	public static function sync_from_central_hub( ?string $snapshot_url = null ): array {
		$start_time = microtime( true );
		$url = ! empty( $snapshot_url ) ? $snapshot_url : self::SNAPSHOT_ENDPOINT;

		Card_Vault_Catalog_DB::ensure_storage_directory();
		$db_dir   = Card_Vault_Catalog_DB::get_db_dir();
		$final_db = Card_Vault_Catalog_DB::get_db_path();
		$tmp_gz   = $db_dir . '/cards.db.download.gz';
		$tmp_db   = $db_dir . '/cards.db.tmp';

		// 1. Download compressed archive
		$response = wp_remote_get( $url, array(
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $tmp_gz,
		) );

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_gz );
			return array(
				'success'     => false,
				'elapsed_sec' => microtime( true ) - $start_time,
				'error'       => $response->get_error_message(),
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status !== 200 ) {
			@unlink( $tmp_gz );
			return array(
				'success'     => false,
				'elapsed_sec' => microtime( true ) - $start_time,
				'error'       => "Hub returned HTTP {$status}",
			);
		}

		// 2. Stream decompress directly to cards.db.tmp
		$gz = gzopen( $tmp_gz, 'rb' );
		if ( ! $gz ) {
			@unlink( $tmp_gz );
			return array(
				'success'     => false,
				'elapsed_sec' => microtime( true ) - $start_time,
				'error'       => 'Failed to open gzip archive.',
			);
		}

		$out = fopen( $tmp_db, 'wb' );
		if ( ! $out ) {
			gzclose( $gz );
			@unlink( $tmp_gz );
			return array(
				'success'     => false,
				'elapsed_sec' => microtime( true ) - $start_time,
				'error'       => 'Failed to open destination tmp database.',
			);
		}

		while ( ! gzeof( $gz ) ) {
			fwrite( $out, gzread( $gz, 65536 ) );
		}

		gzclose( $gz );
		fclose( $out );
		@unlink( $tmp_gz );

		// 3. Verify SQLite header on uncompressed tmp file
		$verify_handle = fopen( $tmp_db, 'rb' );
		$header = fread( $verify_handle, 16 );
		fclose( $verify_handle );

		if ( strpos( $header, 'SQLite format 3' ) !== 0 ) {
			@unlink( $tmp_db );
			return array(
				'success'     => false,
				'elapsed_sec' => microtime( true ) - $start_time,
				'error'       => 'Decompressed file is not a valid SQLite database.',
			);
		}

		// 4. Atomic Rename (Zero downtime)
		if ( ! rename( $tmp_db, $final_db ) ) {
			@unlink( $tmp_db );
			return array(
				'success'     => false,
				'elapsed_sec' => microtime( true ) - $start_time,
				'error'       => 'Failed to swap tmp database into active path.',
			);
		}

		// Remove any stale WAL files from previous database
		@unlink( $final_db . '-wal' );
		@unlink( $final_db . '-shm' );

		update_option( 'card_vault_catalog_last_synced', time() );
		update_option( 'card_vault_catalog_last_checked', time() );

		return array(
			'success'     => true,
			'elapsed_sec' => round( microtime( true ) - $start_time, 2 ),
		);
	}

	/**
	 * Export current cards.db as a compressed gzip archive for distribution.
	 *
	 * @param string|null $dest_path Optional destination path for cards.db.gz.
	 * @return string|false Path to compressed archive or false on failure.
	 */
	public static function export_compressed_snapshot( ?string $dest_path = null ) {
		$src_db = Card_Vault_Catalog_DB::get_db_path();
		if ( ! file_exists( $src_db ) ) {
			return false;
		}

		// Run vacuum & optimize before compressing
		try {
			$pdo = Card_Vault_Catalog_DB::get_connection();
			$pdo->exec( 'PRAGMA optimize;' );
			$pdo->exec( 'VACUUM;' );
		} catch ( Exception $e ) {
			// Continue even if vacuum fails
		}

		$out_path = ! empty( $dest_path ) ? $dest_path : Card_Vault_Catalog_DB::get_db_dir() . '/cards.db.gz';

		$in = fopen( $src_db, 'rb' );
		if ( ! $in ) {
			return false;
		}

		$out = gzopen( $out_path, 'wb9' );
		if ( ! $out ) {
			fclose( $in );
			return false;
		}

		while ( ! feof( $in ) ) {
			gzwrite( $out, fread( $in, 65536 ) );
		}

		fclose( $in );
		gzclose( $out );

		// Write version.json manifest alongside snapshot
		$version_path = dirname( $out_path ) . '/version.json';
		$version_data = array(
			'version'   => 'v' . gmdate( 'Ymd.His' ),
			'timestamp' => time(),
			'sha256'    => hash_file( 'sha256', $out_path ),
			'size_mb'   => round( filesize( $out_path ) / ( 1024 * 1024 ), 2 ),
		);
		file_put_contents( $version_path, wp_json_encode( $version_data, JSON_PRETTY_PRINT ) );

		return $out_path;
	}
}
