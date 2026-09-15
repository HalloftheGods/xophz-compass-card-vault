<?php
/**
 * TCG Catalog Crawler and Mathematical Pacing Engine.
 *
 * Traverses all categories and groups from tcgcsv.com, mathematically calculates
 * rate limits and delay intervals based on remaining groups and target completion hours,
 * and populates the local SQLite card catalog (cards.db) group by group.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Catalog_Crawler {

	const STATE_OPTION = 'card_vault_catalog_crawler_state';
	const CATEGORIES_TRANSIENT = 'card_vault_tcg_categories_list';
	const CRON_HOOK_STEP = 'card_vault_catalog_crawler_step';
	const CRON_HOOK_DAILY = 'card_vault_catalog_daily_crawler_cron';
	const DEFAULT_TARGET_HOURS = 24.0;
	const MIN_DELAY_SECONDS = 5;

	/**
	 * Initialize crawler cron hooks and listeners.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK_STEP, array( __CLASS__, 'process_next_step' ) );
		add_action( self::CRON_HOOK_DAILY, array( __CLASS__, 'handle_daily_crawler_cron' ) );

		// Register daily scheduled event at 20:00 UTC if not set
		if ( ! wp_next_scheduled( self::CRON_HOOK_DAILY ) ) {
			$utc_epoch = strtotime( 'today 20:00:00 UTC' );
			if ( $utc_epoch <= time() ) {
				$utc_epoch = strtotime( 'tomorrow 20:00:00 UTC' );
			}
			wp_schedule_event( $utc_epoch, 'daily', self::CRON_HOOK_DAILY );
		}
	}

	/**
	 * Triggered on plugin activation to initialize local database build if needed.
	 */
	public static function init_on_activation(): void {
		$status = Card_Vault_Catalog_DB::get_status();
		$cards_count = (int) ( $status['total_cards'] ?? 0 );

		// If database is empty or not yet populated, auto-initiate crawler
		$is_database_empty = $cards_count === 0;
		$state = self::get_raw_state();
		$is_idle = empty( $state['status'] ) || $state['status'] === 'idle';

		if ( $is_database_empty && $is_idle ) {
			self::start_crawler( array(
				'target_hours' => self::DEFAULT_TARGET_HOURS,
				'force'        => false,
			) );
		}
	}

	/**
	 * Fetch available categories from tcgcsv.com (cached for 24 hours).
	 *
	 * @param bool $force_refresh Force fresh fetch bypass cache.
	 * @return array List of category records.
	 */
	public static function get_available_categories( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CATEGORIES_TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$url = 'https://tcgcsv.com/tcgplayer/categories';
		$response = wp_remote_get( $url, array(
			'timeout'    => 20,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (CardVaultCatalogSync/1.0)',
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$categories = $body['results'] ?? ( is_array( $body ) ? $body : array() );

		// Filter out categories with no valid ID or known non-card categories
		$filtered = array();
		foreach ( $categories as $cat ) {
			$cid = (int) ( $cat['categoryId'] ?? 0 );
			if ( $cid <= 0 || in_array( $cid, array( 21 ), true ) ) {
				continue;
			}
			$filtered[] = array(
				'categoryId'   => $cid,
				'name'         => (string) ( $cat['name'] ?? "Category #{$cid}" ),
				'displayName'  => (string) ( $cat['displayName'] ?? ( $cat['name'] ?? "Category #{$cid}" ) ),
				'modifiedOn'   => (string) ( $cat['modifiedOn'] ?? '' ),
			);
		}

		if ( ! empty( $filtered ) ) {
			set_transient( self::CATEGORIES_TRANSIENT, $filtered, 24 * HOUR_IN_SECONDS );
		}

		return $filtered;
	}

	/**
	 * Build complete queue manifest across specified or all categories.
	 *
	 * @param array<int> $category_ids List of category IDs to crawl (empty = all).
	 * @param bool       $force Re-import sets already in SQLite.
	 * @return array Manifest breakdown and pending queue.
	 */
	public static function build_manifest( array $category_ids = array(), bool $force = false ): array {
		$all_categories = self::get_available_categories();
		$target_categories = array();

		$has_specific_categories = count( $category_ids ) > 0;
		if ( $has_specific_categories ) {
			$id_map = array_flip( array_map( 'intval', $category_ids ) );
			foreach ( $all_categories as $cat ) {
				if ( isset( $id_map[ (int) $cat['categoryId'] ] ) ) {
					$target_categories[] = $cat;
				}
			}
		} else {
			$selected_cat_ids = Card_Vault_Catalog_DB::get_selected_categories();
			$id_map           = array_flip( array_map( 'intval', $selected_cat_ids ) );
			foreach ( $all_categories as $cat ) {
				if ( isset( $id_map[ (int) $cat['categoryId'] ] ) ) {
					$target_categories[] = $cat;
				}
			}
			if ( empty( $target_categories ) ) {
				$target_categories = $all_categories;
			}
		}

		// Retrieve synced groups from subsite MySQL database
		$synced = Card_Vault_Catalog_DB::get_synced_groups();
		$synced_map = array();
		foreach ( $synced as $s ) {
			$key = ((int) $s['category_id']) . '_' . ((int) $s['group_id']);
			$synced_map[ $key ] = true;
		}

		// Check for sets disabled in settings
		global $wpdb;
		$sets_table    = Card_Vault_Catalog_DB::get_sets_table_name();
		$disabled_gids = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sets_table ) ) === $sets_table ) {
			$disabled_rows = $wpdb->get_col( "SELECT group_id FROM {$sets_table} WHERE is_enabled = 0" );
			$disabled_gids = array_flip( array_map( 'intval', $disabled_rows ?: array() ) );
		}

		$scope = Card_Vault_Catalog_DB::get_sets_scope();

		$queue = array();
		$category_summaries = array();
		$total_groups = 0;
		$already_synced_count = 0;

		foreach ( $target_categories as $cat ) {
			$cid = (int) $cat['categoryId'];
			$cname = (string) $cat['name'];

			// Skip known categories with zero groups
			if ( in_array( $cid, array( 69, 70 ), true ) ) {
				continue;
			}

			$groups = Card_Vault_Catalog_Importer::get_available_groups( $cid );

			// Apply sets scope if configured (e.g. limit to newest N sets per category)
			if ( $scope !== 'all' && is_numeric( $scope ) ) {
				$limit  = max( 1, (int) $scope );
				$groups = array_slice( $groups, 0, $limit );
			}

			$cat_total = count( $groups );
			$cat_synced = 0;

			foreach ( $groups as $g ) {
				$gid = (int) ( $g['groupId'] ?? 0 );
				if ( $gid <= 0 || isset( $disabled_gids[ $gid ] ) ) {
					continue;
				}

				$total_groups++;
				$cache_key = "{$cid}_{$gid}";
				$is_already_synced = isset( $synced_map[ $cache_key ] );

				if ( $is_already_synced ) {
					$cat_synced++;
					$already_synced_count++;
					if ( ! $force ) {
						continue; // Skip already synced
					}
				}

				$queue[] = array(
					'category_id'   => $cid,
					'category_name' => $cname,
					'group_id'      => $gid,
					'group_name'    => (string) ( $g['name'] ?? "Set #{$gid}" ),
					'status'        => 'pending',
					'imported_cards'=> 0,
					'error'         => null,
				);
			}

			$category_summaries[] = array(
				'category_id'   => $cid,
				'name'          => $cname,
				'display_name'  => $cat['displayName'],
				'total_groups'  => $cat_total,
				'synced_groups' => $cat_synced,
			);
		}

		return array(
			'total_categories'     => count( $target_categories ),
			'total_groups'         => $total_groups,
			'already_synced_count' => $already_synced_count,
			'remaining_groups'     => count( $queue ),
			'category_summaries'   => $category_summaries,
			'queue'                => $queue,
		);
	}

	/**
	 * Start or restart the catalog crawler with mathematical pacing.
	 *
	 * @param array $config Crawler options (target_hours, delay_seconds, category_ids, force).
	 * @return array Initialized crawler state.
	 */
	public static function start_crawler( array $config = array() ): array {
		$target_hours = isset( $config['target_hours'] ) ? max( 0.5, (float) $config['target_hours'] ) : self::DEFAULT_TARGET_HOURS;
		$custom_delay = isset( $config['delay_seconds'] ) ? max( self::MIN_DELAY_SECONDS, (int) $config['delay_seconds'] ) : null;
		$category_ids = isset( $config['category_ids'] ) && is_array( $config['category_ids'] ) ? array_map( 'intval', $config['category_ids'] ) : array();
		$force        = ! empty( $config['force'] );

		// Build fresh queue manifest
		$manifest = self::build_manifest( $category_ids, $force );
		$remaining = $manifest['remaining_groups'];

		// Calculate mathematical delay interval
		if ( $custom_delay !== null ) {
			$delay_seconds = $custom_delay;
			$computed_target_hours = $remaining > 0 ? round( ( $remaining * $delay_seconds ) / 3600, 2 ) : 0.0;
		} else {
			$delay_seconds = $remaining > 0 ? max( self::MIN_DELAY_SECONDS, (int) round( ( $target_hours * 3600 ) / $remaining ) ) : 60;
			$computed_target_hours = $target_hours;
		}

		$now = time();
		$eta_timestamp = $now + ( $remaining * $delay_seconds );

		$first_group = $manifest['queue'][0] ?? null;

		$state = array(
			'status'                => $remaining > 0 ? 'running' : 'completed',
			'total_categories'      => $manifest['total_categories'],
			'total_groups'          => $manifest['total_groups'],
			'completed_groups'      => $manifest['already_synced_count'],
			'failed_groups'         => 0,
			'remaining_groups'      => $remaining,
			'imported_cards'        => 0,
			'target_hours'          => $computed_target_hours,
			'delay_seconds'         => $delay_seconds,
			'started_at'            => $now,
			'last_processed_at'     => null,
			'next_run_timestamp'    => $remaining > 0 ? $now : null,
			'eta_timestamp'         => $eta_timestamp,
			'current_category_id'   => $first_group ? $first_group['category_id'] : null,
			'current_category_name' => $first_group ? $first_group['category_name'] : '',
			'current_group_id'      => $first_group ? $first_group['group_id'] : null,
			'current_group_name'    => $first_group ? $first_group['group_name'] : '',
			'last_error'            => null,
			'category_summaries'    => $manifest['category_summaries'],
			'queue'                 => $manifest['queue'],
		);

		update_option( self::STATE_OPTION, $state );

		// Clear existing scheduled single step cron
		wp_clear_scheduled_hook( self::CRON_HOOK_STEP );

		// If groups are queued, trigger the first step immediately
		if ( $remaining > 0 ) {
			wp_schedule_single_event( $now, self::CRON_HOOK_STEP );
		}

		return self::format_state_response( $state );
	}

	/**
	 * Process the next pending group in queue.
	 *
	 * @return array Step execution result.
	 */
	public static function process_next_step(): array {
		$state = self::get_raw_state();
		$is_active = isset( $state['status'] ) && $state['status'] === 'running';

		if ( ! $is_active || empty( $state['queue'] ) ) {
			return array( 'success' => false, 'message' => 'Crawler is not running or queue is empty.' );
		}

		$queue = $state['queue'];
		$target_index = -1;

		foreach ( $queue as $idx => $item ) {
			if ( $item['status'] === 'pending' || $item['status'] === 'processing' ) {
				$target_index = $idx;
				break;
			}
		}

		// If no pending items found, crawler has finished all sets
		if ( $target_index === -1 ) {
			$state['status']             = 'completed';
			$state['next_run_timestamp'] = null;
			$state['remaining_groups']   = 0;
			update_option( self::STATE_OPTION, $state );
			update_option( 'card_vault_catalog_last_synced', time() );
			wp_clear_scheduled_hook( self::CRON_HOOK_STEP );
			return array( 'success' => true, 'message' => 'All sets successfully completed.' );
		}

		$item = $queue[ $target_index ];
		$cid   = (int) $item['category_id'];
		$gid   = (int) $item['group_id'];
		$cname = (string) $item['category_name'];
		$gname = (string) $item['group_name'];

		// Update state to indicate current processing set
		$state['current_category_id']   = $cid;
		$state['current_category_name'] = $cname;
		$state['current_group_id']      = $gid;
		$state['current_group_name']    = $gname;
		$state['queue'][ $target_index ]['status'] = 'processing';
		update_option( self::STATE_OPTION, $state );

		// Execute CSV ingestion for this group
		$result = Card_Vault_Catalog_Importer::import_group_csv( $cid, $gid, $gname, $cname );

		// Update item outcome
		$now = time();
		$is_success = ! empty( $result['success'] );

		if ( $is_success ) {
			$cards_count = (int) ( $result['imported_count'] ?? 0 );
			$state['queue'][ $target_index ]['status']         = 'completed';
			$state['queue'][ $target_index ]['imported_cards'] = $cards_count;
			$state['completed_groups']++;
			$state['imported_cards'] += $cards_count;
		} else {
			$error_msg = (string) ( $result['error'] ?? 'CSV ingestion failed' );
			$state['queue'][ $target_index ]['status'] = 'failed';
			$state['queue'][ $target_index ]['error']  = $error_msg;
			$state['failed_groups']++;
			$state['last_error'] = "Group {$gid} ({$gname}): {$error_msg}";
		}

		$state['remaining_groups']  = max( 0, $state['remaining_groups'] - 1 );
		$state['last_processed_at'] = $now;

		// Check if more items remain
		$has_more_items = $state['remaining_groups'] > 0;
		$delay_sec = max( self::MIN_DELAY_SECONDS, (int) ( $state['delay_seconds'] ?? 60 ) );

		if ( $has_more_items ) {
			$next_run = $now + $delay_sec;
			$state['next_run_timestamp'] = $next_run;
			$state['eta_timestamp']      = $now + ( $state['remaining_groups'] * $delay_sec );

			// Find next pending group name for telemetry
			for ( $i = $target_index + 1; $i < count( $state['queue'] ); $i++ ) {
				if ( $state['queue'][ $i ]['status'] === 'pending' ) {
					$state['current_category_id']   = $state['queue'][ $i ]['category_id'];
					$state['current_category_name'] = $state['queue'][ $i ]['category_name'];
					$state['current_group_id']      = $state['queue'][ $i ]['group_id'];
					$state['current_group_name']    = $state['queue'][ $i ]['group_name'];
					break;
				}
			}

			update_option( self::STATE_OPTION, $state );

			// Schedule next single-event step
			wp_clear_scheduled_hook( self::CRON_HOOK_STEP );
			wp_schedule_single_event( $next_run, self::CRON_HOOK_STEP );
		} else {
			$state['status']             = 'completed';
			$state['next_run_timestamp'] = null;
			update_option( self::STATE_OPTION, $state );
			update_option( 'card_vault_catalog_last_synced', $now );
			wp_clear_scheduled_hook( self::CRON_HOOK_STEP );
		}

		return array(
			'success'          => $is_success,
			'group_id'         => $gid,
			'group_name'       => $gname,
			'imported_cards'   => $result['imported_count'] ?? 0,
			'remaining_groups' => $state['remaining_groups'],
			'next_run_in_sec'  => $has_more_items ? $delay_sec : 0,
		);
	}

	/**
	 * Pause the crawler.
	 *
	 * @return array Updated crawler status.
	 */
	public static function pause_crawler(): array {
		$state = self::get_raw_state();
		$state['status']             = 'paused';
		$state['next_run_timestamp'] = null;
		update_option( self::STATE_OPTION, $state );
		wp_clear_scheduled_hook( self::CRON_HOOK_STEP );

		return self::format_state_response( $state );
	}

	/**
	 * Resume the crawler from where it stopped.
	 *
	 * @return array Updated crawler status.
	 */
	public static function resume_crawler(): array {
		$state = self::get_raw_state();
		$is_paused = isset( $state['status'] ) && $state['status'] === 'paused';

		if ( $is_paused && ( $state['remaining_groups'] ?? 0 ) > 0 ) {
			$now = time();
			$delay_sec = max( self::MIN_DELAY_SECONDS, (int) ( $state['delay_seconds'] ?? 60 ) );
			$state['status']             = 'running';
			$state['next_run_timestamp'] = $now;
			$state['eta_timestamp']      = $now + ( $state['remaining_groups'] * $delay_sec );
			update_option( self::STATE_OPTION, $state );

			wp_clear_scheduled_hook( self::CRON_HOOK_STEP );
			wp_schedule_single_event( $now, self::CRON_HOOK_STEP );
		}

		return self::format_state_response( $state );
	}

	/**
	 * Clear queue and reset crawler to idle.
	 *
	 * @return array Reset status.
	 */
	public static function reset_crawler(): array {
		wp_clear_scheduled_hook( self::CRON_HOOK_STEP );
		delete_option( self::STATE_OPTION );

		return self::get_status();
	}

	/**
	 * Retrieve live crawler status with self-healing heartbeat check.
	 *
	 * @param bool $auto_advance Self-heal missed crons.
	 * @return array Formatted status response.
	 */
	public static function get_status( bool $auto_advance = true ): array {
		$state = self::get_raw_state();

		// Self-healing: if crawler is marked 'running' and next_run_timestamp was over 30s ago, advance
		$is_running = isset( $state['status'] ) && $state['status'] === 'running';
		$next_run = (int) ( $state['next_run_timestamp'] ?? 0 );
		$is_overdue = $is_running && $next_run > 0 && ( time() >= $next_run + 30 );

		if ( $auto_advance && $is_overdue ) {
			self::process_next_step();
			$state = self::get_raw_state();
		}

		return self::format_state_response( $state );
	}

	/**
	 * Daily recurring cron handler. Checks for new sets and restarts crawler if needed.
	 */
	public static function handle_daily_crawler_cron(): void {
		$state = self::get_raw_state();
		$is_active = isset( $state['status'] ) && in_array( $state['status'], array( 'running', 'building_manifest' ), true );

		if ( ! $is_active ) {
			self::start_crawler( array(
				'target_hours' => self::DEFAULT_TARGET_HOURS,
				'force'        => false,
			) );
		}
	}

	/**
	 * Get raw state array from WordPress options.
	 *
	 * @return array Raw crawler state.
	 */
	private static function get_raw_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Format crawler state for clean API and UI consumption.
	 *
	 * @param array $state Raw state.
	 * @return array Formatted status.
	 */
	private static function format_state_response( array $state ): array {
		$total_groups = max( 0, (int) ( $state['total_groups'] ?? 0 ) );
		$completed    = max( 0, (int) ( $state['completed_groups'] ?? 0 ) );
		$remaining    = max( 0, (int) ( $state['remaining_groups'] ?? 0 ) );
		$progress_pct = $total_groups > 0 ? round( ( $completed / $total_groups ) * 100, 1 ) : 0.0;

		$next_run = isset( $state['next_run_timestamp'] ) ? (int) $state['next_run_timestamp'] : 0;
		$seconds_until_next = $next_run > 0 ? max( 0, $next_run - time() ) : 0;

		$queue = $state['queue'] ?? array();
		$recent_queue = array_slice( $queue, 0, 150 );

		return array(
			'status'                => (string) ( $state['status'] ?? 'idle' ),
			'total_categories'      => (int) ( $state['total_categories'] ?? 0 ),
			'total_groups'          => $total_groups,
			'completed_groups'      => $completed,
			'failed_groups'         => (int) ( $state['failed_groups'] ?? 0 ),
			'remaining_groups'      => $remaining,
			'imported_cards'        => (int) ( $state['imported_cards'] ?? 0 ),
			'progress_percentage'   => $progress_pct,
			'target_hours'          => (float) ( $state['target_hours'] ?? self::DEFAULT_TARGET_HOURS ),
			'delay_seconds'         => (int) ( $state['delay_seconds'] ?? 60 ),
			'started_at'            => $state['started_at'] ?? null,
			'last_processed_at'     => $state['last_processed_at'] ?? null,
			'next_run_timestamp'    => $next_run > 0 ? $next_run : null,
			'seconds_until_next'    => $seconds_until_next,
			'eta_timestamp'         => $state['eta_timestamp'] ?? null,
			'current_category_id'   => $state['current_category_id'] ?? null,
			'current_category_name' => $state['current_category_name'] ?? '',
			'current_group_id'      => $state['current_group_id'] ?? null,
			'current_group_name'    => $state['current_group_name'] ?? '',
			'last_error'            => $state['last_error'] ?? null,
			'category_summaries'    => $state['category_summaries'] ?? array(),
			'queue'                 => $recent_queue,
		);
	}
}
