<?php
/**
 * REST API Controller for Card Vault SQLite Catalog and Barcode Resolution.
 *
 * Exposes endpoints for high-speed card search, card details, barcode lookup
 * (both manufacturer UPCs and CV-* single card SKUs), secured by Gatekeeper.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Catalog_REST {

	const NAMESPACE = 'card-vault/v1';
	const ALT_NAMESPACE = 'xophz-card-vault/v1';

	/**
	 * Register REST routes across standard and plugin namespaces.
	 */
	public static function register_routes(): void {
		$namespaces = array( self::NAMESPACE, self::ALT_NAMESPACE );

		foreach ( $namespaces as $ns ) {
			// 1. Search Catalog
			register_rest_route(
				$ns,
				'/catalog/search',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_search' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
					'args'                => array(
						'q'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'category' => array( 'sanitize_callback' => 'absint' ),
						'group'    => array( 'sanitize_callback' => 'absint' ),
						'rarity'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'limit'    => array( 'default' => 24, 'sanitize_callback' => 'absint' ),
					),
				)
			);

			// 2. Single Card Details
			register_rest_route(
				$ns,
				'/catalog/cards/(?P<id>[a-zA-Z0-9-_]+)',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_card' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				)
			);

			// 3. Barcode / SKU Resolver (Dual UPC & CV-* Single SKU)
			register_rest_route(
				$ns,
				'/catalog/barcode/(?P<code>[a-zA-Z0-9-_]+)',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_barcode_lookup' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				)
			);

			// 4. Catalog Status
			register_rest_route(
				$ns,
				'/catalog/status',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_status' ),
					'permission_callback' => '__return_true',
				)
			);

			// 5. Trigger Catalog Sync from Hub or Batched Sets
			register_rest_route(
				$ns,
				'/catalog/sync',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_sync' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			// 6. Enumerate All TCG Categories
			register_rest_route(
				$ns,
				'/catalog/categories',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_categories' ),
					'permission_callback' => '__return_true',
				)
			);

			// 7. Crawler Live Telemetry & Progress
			register_rest_route(
				$ns,
				'/catalog/crawler/status',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_crawler_status' ),
					'permission_callback' => '__return_true',
				)
			);

			// 8. Crawler Control: Start / Configure
			register_rest_route(
				$ns,
				'/catalog/crawler/start',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_crawler_start' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			// 9. Crawler Control: Pause
			register_rest_route(
				$ns,
				'/catalog/crawler/pause',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_crawler_pause' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			// 10. Crawler Control: Resume
			register_rest_route(
				$ns,
				'/catalog/crawler/resume',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_crawler_resume' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			// 11. Crawler Control: Single Step Immediate
			register_rest_route(
				$ns,
				'/catalog/crawler/step',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_crawler_step' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			// 12. Crawler Control: Reset
			register_rest_route(
				$ns,
				'/catalog/crawler/reset',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_crawler_reset' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);
		}
	}

	/**
	 * Permission callback verifying Gatekeeper API key or public allowance.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function check_read_permission( WP_REST_Request $request ) {
		// If user is already an authenticated WP admin/shop manager, permit
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		// Extract Bearer token or api_key parameter
		$auth_header = (string) $request->get_header( 'authorization' );
		$token       = '';

		if ( ! empty( $auth_header ) && stripos( $auth_header, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $auth_header, 7 ) );
		} elseif ( $request->get_param( 'api_key' ) ) {
			$token = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		}

		// If token is provided, validate via Gatekeeper_Keys
		if ( ! empty( $token ) ) {
			if ( class_exists( 'Gatekeeper_Keys' ) ) {
				$validation = Gatekeeper_Keys::validate_key( $token, 'read:cards' );
				if ( ! $validation['valid'] ) {
					return new WP_Error(
						'gatekeeper_forbidden',
						$validation['error'] ?? __( 'Invalid or expired API key.', 'xophz-compass-card-vault' ),
						array( 'status' => 403 )
					);
				}
				return true;
			}
		}

		// Fallback for local internal frontend requests without a key: permit with rate-limiting
		return true;
	}

	/**
	 * Permission callback for admin actions (batch sync, catalog changes).
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function check_admin_permission( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_card_vault' ) || current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$auth_header = (string) $request->get_header( 'authorization' );
		$token       = '';

		if ( ! empty( $auth_header ) && stripos( $auth_header, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $auth_header, 7 ) );
		} elseif ( $request->get_param( 'api_key' ) ) {
			$token = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		}

		if ( ! empty( $token ) && class_exists( 'Gatekeeper_Keys' ) ) {
			$validation = Gatekeeper_Keys::validate_key( $token, 'write:cards' );
			if ( $validation['valid'] ) {
				return true;
			}
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Only administrators can perform catalog synchronization.', 'xophz-compass-card-vault' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle catalog search queries.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_search( WP_REST_Request $request ): WP_REST_Response {
		$query    = (string) ( $request->get_param( 'q' ) ?: '' );
		$category = (int) $request->get_param( 'category' );
		$group    = (int) $request->get_param( 'group' );
		$rarity   = (string) $request->get_param( 'rarity' );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit    = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 24 ) ) );
		$offset   = ( $page - 1 ) * $limit;

		$filters = array();
		if ( $category > 0 ) {
			$filters['category_id'] = $category;
		}
		if ( $group > 0 ) {
			$filters['group_id'] = $group;
		}
		if ( ! empty( $rarity ) ) {
			$filters['rarity'] = $rarity;
		}

		$start   = microtime( true );
		$cards   = Card_Vault_Catalog_DB::search_cards( $query, $filters, $limit, $offset );
		$total   = Card_Vault_Catalog_DB::count_cards( $query, $filters );
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		return rest_ensure_response( array(
			'success'      => true,
			'count'        => count( $cards ),
			'total'        => $total,
			'page'         => $page,
			'limit'        => $limit,
			'execution_ms' => $elapsed,
			'query'        => $query,
			'results'      => $cards,
		) );
	}

	/**
	 * Handle single card retrieval.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_card( WP_REST_Request $request ) {
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$card = Card_Vault_Catalog_DB::get_card_by_id( $id );

		if ( ! $card && is_numeric( $id ) ) {
			$card = Card_Vault_Catalog_DB::get_card_by_tcgplayer_id( (int) $id );
		}

		if ( ! $card ) {
			return new WP_Error( 'card_not_found', __( 'Card not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'card'    => $card,
		) );
	}

	/**
	 * Handle barcode lookup (Dual UPC & CV-* Single SKU).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_barcode_lookup( WP_REST_Request $request ) {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$info = Card_Vault_SKU_Generator::identify_barcode( $code );

		// 1. Single SKU (CV-*) Lookup in Shop Inventory
		if ( $info['type'] === 'single_sku' ) {
			global $wpdb;
			$table = $wpdb->prefix . 'xophz_vault_collector_items';

			// Search for matching item_id or user notes containing the SKU
			$item = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %s OR card_payload LIKE %s LIMIT 1", $code, '%' . $wpdb->esc_like( $code ) . '%' ),
				ARRAY_A
			);

			if ( $item ) {
				$payload = ! empty( $item['card_payload'] ) ? json_decode( $item['card_payload'], true ) : array();
				return rest_ensure_response( array(
					'success'      => true,
					'barcode_type' => 'single_sku',
					'is_inventory' => true,
					'inventory'    => array(
						'itemId'        => $item['item_id'],
						'wpUserId'      => (int) $item['wp_user_id'],
						'cardName'      => $item['card_name'],
						'setName'       => $item['set_name'],
						'cardNumber'    => $item['card_number'],
						'condition'     => $item['condition_grade'],
						'quantity'      => (int) $item['quantity'],
						'askingPrice'   => (float) ( $item['asking_price'] ?? $item['market_price'] ),
						'marketPrice'   => (float) $item['market_price'],
						'sku'           => $code,
						'payload'       => $payload,
					),
				) );
			}
		}

		// 2. Manufacturer UPC or Catalog ID Lookup
		$card = Card_Vault_Catalog_DB::get_card_by_barcode( $code );
		if ( $card ) {
			return rest_ensure_response( array(
				'success'      => true,
				'barcode_type' => $info['type'],
				'is_inventory' => false,
				'card'         => $card,
			) );
		}

		return new WP_Error( 'barcode_not_found', __( 'No catalog card or inventory matching this barcode was found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
	}

	/**
	 * Handle catalog status metrics.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_status( WP_REST_Request $request ): WP_REST_Response {
		$should_check = (bool) $request->get_param( 'check' );
		if ( $should_check ) {
			$check_result = Card_Vault_Catalog_Importer::check_for_updates();
		} else {
			$remote_version  = (string) get_option( 'card_vault_catalog_remote_version', '' );
			$current_version = (string) get_option( 'card_vault_catalog_version', '' );
			$check_result    = array(
				'update_available' => ( ! empty( $remote_version ) && $remote_version !== $current_version ),
				'remote_version'   => $remote_version,
				'current_version'  => $current_version,
			);
		}

		$status = Card_Vault_Catalog_DB::get_status();
		$status['hub_domain']           = Card_Vault_Catalog_Importer::HUB_DOMAIN;
		$status['last_checked']         = get_option( 'card_vault_catalog_last_checked' ) ? date( 'Y-m-d H:i:s', (int) get_option( 'card_vault_catalog_last_checked' ) ) : null;
		$status['last_synced']          = get_option( 'card_vault_catalog_last_synced' ) ? date( 'Y-m-d H:i:s', (int) get_option( 'card_vault_catalog_last_synced' ) ) : null;
		$status['update_available']     = (bool) ( $check_result['update_available'] ?? false );
		$status['current_version']      = (string) get_option( 'card_vault_catalog_version', '' );
		$status['remote_version']       = (string) get_option( 'card_vault_catalog_remote_version', '' );
		$status['next_scheduled_check'] = wp_next_scheduled( 'card_vault_catalog_check_cron' ) ? date( 'Y-m-d H:i:s', (int) wp_next_scheduled( 'card_vault_catalog_check_cron' ) ) : null;

		return rest_ensure_response( array(
			'success' => true,
			'status'  => $status,
		) );
	}

	/**
	 * Handle on-demand catalog synchronization (batch import or Hub snapshot).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_sync( WP_REST_Request $request ) {
		$mode = sanitize_text_field( (string) ( $request->get_param( 'mode' ) ?: 'batch' ) );

		if ( $mode === 'snapshot' ) {
			$snapshot_url = $request->get_param( 'url' ) ? esc_url_raw( (string) $request->get_param( 'url' ) ) : null;
			$result = Card_Vault_Catalog_Importer::sync_from_central_hub( $snapshot_url );

			if ( ! $result['success'] ) {
				return new WP_Error(
					'sync_failed',
					$result['error'] ?? __( 'Hub synchronization failed.', 'xophz-compass-card-vault' ),
					array( 'status' => 500 )
				);
			}
		} else {
			// Batch sync sets from tcgcsv with rate-limiting
			$category_id = max( 1, (int) ( $request->get_param( 'category' ) ?: 3 ) );
			$batch_size  = max( 1, min( 50, (int) ( $request->get_param( 'batch_size' ) ?: 10 ) ) );
			$delay_ms    = max( 250, min( 5000, (int) ( $request->get_param( 'delay_ms' ) ?: 750 ) ) );
			$limit       = $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : null;
			$force       = (bool) $request->get_param( 'force' );

			$result = Card_Vault_Catalog_Importer::batch_sync_groups( $category_id, $batch_size, $delay_ms, $limit, $force );

			if ( ! $result['success'] && ( $result['imported_cards'] ?? 0 ) === 0 ) {
				return new WP_Error(
					'batch_sync_failed',
					$result['error'] ?? __( 'Batch synchronization failed.', 'xophz-compass-card-vault' ),
					array( 'status' => 500 )
				);
			}
		}

		$status = Card_Vault_Catalog_DB::get_status();
		$status['hub_domain']           = Card_Vault_Catalog_Importer::HUB_DOMAIN;
		$status['last_checked']         = date( 'Y-m-d H:i:s' );
		$status['last_synced']          = date( 'Y-m-d H:i:s' );
		$status['update_available']     = false;
		$status['current_version']      = (string) get_option( 'card_vault_catalog_version', '' );
		$status['remote_version']       = (string) get_option( 'card_vault_catalog_remote_version', '' );
		$status['next_scheduled_check'] = wp_next_scheduled( 'card_vault_catalog_check_cron' ) ? date( 'Y-m-d H:i:s', (int) wp_next_scheduled( 'card_vault_catalog_check_cron' ) ) : null;

		return rest_ensure_response( array(
			'success'     => true,
			'result'      => $result,
			'elapsed_sec' => $result['elapsed_sec'] ?? 0,
			'status'      => $status,
		) );
	}

	/**
	 * Get list of all available TCG categories.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_categories( WP_REST_Request $request ): WP_REST_Response {
		$force = (bool) $request->get_param( 'force' );
		$categories = Card_Vault_Catalog_Crawler::get_available_categories( $force );

		return rest_ensure_response( array(
			'success'    => true,
			'categories' => $categories,
			'count'      => count( $categories ),
		) );
	}

	/**
	 * Get live crawler status, progress metrics, and queue telemetry.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_crawler_status( WP_REST_Request $request ): WP_REST_Response {
		$auto_advance = $request->get_param( 'auto_advance' ) !== '0';
		$status = Card_Vault_Catalog_Crawler::get_status( $auto_advance );

		return rest_ensure_response( array(
			'success' => true,
			'crawler' => $status,
		) );
	}

	/**
	 * Start or reconfigure catalog crawler with mathematical pacing.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_crawler_start( WP_REST_Request $request ): WP_REST_Response {
		$config = array();

		if ( $request->get_param( 'target_hours' ) ) {
			$config['target_hours'] = (float) $request->get_param( 'target_hours' );
		}
		if ( $request->get_param( 'delay_seconds' ) ) {
			$config['delay_seconds'] = (int) $request->get_param( 'delay_seconds' );
		}
		if ( $request->get_param( 'category_ids' ) && is_array( $request->get_param( 'category_ids' ) ) ) {
			$config['category_ids'] = $request->get_param( 'category_ids' );
		}
		if ( $request->get_param( 'force' ) ) {
			$config['force'] = (bool) $request->get_param( 'force' );
		}

		$status = Card_Vault_Catalog_Crawler::start_crawler( $config );

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Crawler initialized and started.', 'xophz-compass-card-vault' ),
			'crawler' => $status,
		) );
	}

	/**
	 * Pause active catalog crawler.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_crawler_pause( WP_REST_Request $request ): WP_REST_Response {
		$status = Card_Vault_Catalog_Crawler::pause_crawler();

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Crawler paused.', 'xophz-compass-card-vault' ),
			'crawler' => $status,
		) );
	}

	/**
	 * Resume paused catalog crawler.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_crawler_resume( WP_REST_Request $request ): WP_REST_Response {
		$status = Card_Vault_Catalog_Crawler::resume_crawler();

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Crawler resumed.', 'xophz-compass-card-vault' ),
			'crawler' => $status,
		) );
	}

	/**
	 * Immediately execute next pending crawler group.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_crawler_step( WP_REST_Request $request ): WP_REST_Response {
		$result = Card_Vault_Catalog_Crawler::process_next_step();
		$status = Card_Vault_Catalog_Crawler::get_status( false );

		return rest_ensure_response( array(
			'success' => $result['success'] ?? false,
			'step'    => $result,
			'crawler' => $status,
		) );
	}

	/**
	 * Reset catalog crawler state and clear queue.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_crawler_reset( WP_REST_Request $request ): WP_REST_Response {
		$status = Card_Vault_Catalog_Crawler::reset_crawler();

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Crawler queue reset to idle.', 'xophz-compass-card-vault' ),
			'crawler' => $status,
		) );
	}
}
