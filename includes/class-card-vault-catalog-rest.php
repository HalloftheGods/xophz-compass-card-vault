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
					'permission_callback' => array( __CLASS__, 'check_public_read_permission' ),
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

			// 2d. Batch Price Resync for Vault Collection. Must be registered before
			// /catalog/cards/{id}, which would otherwise match 'prices' first.
			register_rest_route(
				$ns,
				'/catalog/cards/prices',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_resync_prices' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				)
			);

			// 2. Single Card Details & Admin Card Updates
			register_rest_route(
				$ns,
				'/catalog/cards/(?P<id>[a-zA-Z0-9-_]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'handle_get_card' ),
						'permission_callback' => array( __CLASS__, 'check_public_read_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'handle_update_card' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					),
				)
			);

			// 2b. Card Image Resolver & WebP Cache Endpoint
			register_rest_route(
				$ns,
				'/catalog/cards/(?P<id>[a-zA-Z0-9-_]+)/image',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_card_image' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'thumb'        => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
						'redirect'     => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
					),
				)
			);

			// 2c. Image Cache Diagnostics
			register_rest_route(
				$ns,
				'/catalog/cache/stats',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_cache_stats' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				)
			);

			// 2d. Image Cache Purge
			register_rest_route(
				$ns,
				'/catalog/cache/purge',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_cache_purge' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);

			// 2e. Card Price History
			register_rest_route(
				$ns,
				'/catalog/cards/(?P<id>[a-zA-Z0-9-_]+)/history',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get_card_history' ),
					'permission_callback' => array( __CLASS__, 'check_public_read_permission' ),
					'args'                => array(
						'days' => array( 'default' => 30, 'sanitize_callback' => 'absint' ),
					),
				)
			);

			// 2f. Portfolio Valuation History
			register_rest_route(
				$ns,
				'/catalog/portfolio/history',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_portfolio_history' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
				)
			);

			// 2g. Upload PriceCharting CSV
			register_rest_route(
				$ns,
				'/catalog/upload-pricecharting',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_upload_pricecharting' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
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

			// 6b. Catalog Sync Ingestion Settings
			register_rest_route(
				$ns,
				'/catalog/sync/settings',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'handle_get_sync_settings' ),
						'permission_callback' => array( __CLASS__, 'check_read_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'handle_update_sync_settings' ),
						'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					),
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
		$result = self::check_read_access( $request );
		if ( null !== $result ) {
			return $result;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Log in or present a valid API key to use this endpoint.', 'xophz-compass-card-vault' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Permission callback for catalog data that is public by design (search, card
	 * details, card price history). Anonymous callers are allowed only when they
	 * present no API key; a key that is presented but invalid, revoked or
	 * under-scoped is refused. No rate limiting is applied.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public static function check_public_read_permission( WP_REST_Request $request ) {
		$result = self::check_read_access( $request );
		if ( null !== $result ) {
			return $result;
		}

		return true;
	}

	/**
	 * Shared read-access decision.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error|null True for a logged-in user or a valid key, a
	 *                            WP_Error for a presented key that fails, and
	 *                            null when the caller is anonymous with no key.
	 */
	private static function check_read_access( WP_REST_Request $request ) {
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		$auth_header = (string) $request->get_header( 'authorization' );
		$token       = '';

		if ( ! empty( $auth_header ) && stripos( $auth_header, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $auth_header, 7 ) );
		} elseif ( $request->get_param( 'api_key' ) ) {
			$token = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		}

		if ( '' === $token ) {
			return null;
		}

		if ( ! class_exists( 'Gatekeeper_Keys' ) ) {
			return new WP_Error(
				'gatekeeper_unavailable',
				__( 'API keys cannot be validated on this site.', 'xophz-compass-card-vault' ),
				array( 'status' => 403 )
			);
		}

		$validation = Gatekeeper_Keys::validate_key( $token, 'read:cards' );
		if ( empty( $validation['valid'] ) ) {
			return new WP_Error(
				'gatekeeper_forbidden',
				$validation['error'] ?? __( 'Invalid or expired API key.', 'xophz-compass-card-vault' ),
				array( 'status' => 403 )
			);
		}

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
	public static function handle_search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$query    = (string) ( $request->get_param( 'q' ) ?: '' );
		$category = (int) $request->get_param( 'category' );
		$group    = (int) $request->get_param( 'group' );
		$groups   = (string) ( $request->get_param( 'groups' ) ?: '' );
		$rarity   = (string) $request->get_param( 'rarity' );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit    = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 24 ) ) );
		if ( ( $page - 1 ) * $limit > 10000 ) {
			return new WP_Error( 'page_too_deep', __( 'Page is beyond the browsable range; narrow the search instead.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}
		$offset   = ( $page - 1 ) * $limit;

		$filters = array();
		if ( $category > 0 ) {
			$filters['category_id'] = $category;
		}
		if ( ! empty( $groups ) ) {
			$filters['group_ids'] = array_filter( array_map( 'intval', explode( ',', $groups ) ) );
		} elseif ( $group > 0 ) {
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

		$group_id   = (int) ( $card['group_id'] ?? 0 );
		$cached_url = Card_Vault_Image_Cache::get_cached_image_url( $card['id'], $group_id );
		if ( $cached_url ) {
			$card['cached_image_url'] = $cached_url;
		}

		return rest_ensure_response( array(
			'success' => true,
			'card'    => $card,
		) );
	}

	/**
	 * Handle admin update of an existing catalog card (product ID, prices).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update_card( WP_REST_Request $request ) {
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$card = Card_Vault_Catalog_DB::get_card_by_id( $id );

		if ( ! $card && is_numeric( $id ) ) {
			$card = Card_Vault_Catalog_DB::get_card_by_tcgplayer_id( (int) $id );
		}

		if ( ! $card ) {
			return new WP_Error( 'card_not_found', __( 'Card not found in master catalog.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		$updates = array();
		$tcgplayer_id = $request->get_param( 'tcgplayer_id' ) ?? $request->get_param( 'tcgplayerId' );
		if ( $tcgplayer_id !== null ) {
			$updates['tcgplayer_id']  = absint( $tcgplayer_id );
			$updates['tcgplayer_url'] = 'https://www.tcgplayer.com/product/' . absint( $tcgplayer_id );
		}

		$market_price = $request->get_param( 'market_price' ) ?? $request->get_param( 'marketPrice' );
		if ( $market_price !== null ) {
			$updates['market_price'] = floatval( $market_price );
		}

		if ( empty( $updates ) ) {
			return new WP_Error( 'no_updates_provided', __( 'No valid fields provided for update.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		$success = Card_Vault_Catalog_DB::update_card( $card['id'], $updates );
		if ( ! $success ) {
			return new WP_Error( 'update_failed', __( 'Failed to update catalog card in database.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$updated_card = Card_Vault_Catalog_DB::get_card_by_id( $card['id'] );

		return rest_ensure_response( array(
			'success' => true,
			'card'    => $updated_card,
			'message' => __( 'Catalog card updated successfully.', 'xophz-compass-card-vault' ),
		) );
	}

	/**
	 * Handle batch price resync for multiple cards in player's vault.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_resync_prices( WP_REST_Request $request ) {
		$cards = $request->get_param( 'cards' );
		if ( ! is_array( $cards ) ) {
			$json = $request->get_json_params();
			if ( isset( $json['cards'] ) && is_array( $json['cards'] ) ) {
				$cards = $json['cards'];
			} elseif ( is_array( $json ) ) {
				$cards = $json;
			}
		}
		if ( ! is_array( $cards ) ) {
			$cards = array();
		}

		$start = microtime( true );
		$prices = Card_Vault_Catalog_DB::get_cards_pricing_batch( $cards );
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		return rest_ensure_response( array(
			'success'      => true,
			'count'        => count( $prices ),
			'execution_ms' => $elapsed,
			'prices'       => $prices,
		) );
	}

	/**
	 * Handle retrieving price history for a single card.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_card_history( WP_REST_Request $request ) {
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$days = max( 1, min( 365, (int) $request->get_param( 'days' ) ?: 30 ) );

		$history = Card_Vault_Catalog_DB::get_card_price_history( $id, $days );

		return rest_ensure_response( array(
			'success' => true,
			'card_id' => $id,
			'days'    => $days,
			'count'   => count( $history ),
			'history' => $history,
		) );
	}

	/**
	 * Handle computing portfolio valuation history across multiple items.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_portfolio_history( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$items = is_array( $body['items'] ?? null ) ? $body['items'] : array();
		$days  = max( 1, min( 365, (int) ( $body['days'] ?? 30 ) ) );

		if ( empty( $items ) ) {
			return rest_ensure_response( array(
				'success' => true,
				'days'    => $days,
				'count'   => 0,
				'points'  => array(),
			) );
		}

		$points = Card_Vault_Catalog_DB::get_portfolio_history( $items, $days );

		return rest_ensure_response( array(
			'success' => true,
			'days'    => $days,
			'count'   => count( $points ),
			'points'  => $points,
		) );
	}

	/**
	 * Handle manual PriceCharting CSV upload from admin.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_upload_pricecharting( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'missing_file', __( 'No CSV file uploaded.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		$tmp_file = $files['file']['tmp_name'];
		$result   = Card_Vault_Catalog_Importer::ingest_pricecharting_csv( $tmp_file );

		return rest_ensure_response( $result );
	}

	/**
	 * Serve or transcode-and-cache card image in WebP format.
	 *
	 * Supports redirect mode (redirect=1) to let Nginx/CDN serve the static WebP
	 * file directly on subsequent requests.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_card_image( WP_REST_Request $request ) {
		$id           = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$thumb        = (bool) $request->get_param( 'thumb' );
		$redirect     = (bool) $request->get_param( 'redirect' );

		// 1. This route is public: the card must exist in the catalog, and the image source
		// and cache key come from the catalog row only. Request-supplied URLs and groups are ignored.
		$card = Card_Vault_Catalog_DB::get_card_by_id( $id );
		$is_numeric_fallback = ! $card && is_numeric( $id );
		if ( $is_numeric_fallback ) {
			$card = Card_Vault_Catalog_DB::get_card_by_tcgplayer_id( (int) $id );
		}
		if ( ! $card ) {
			return new WP_Error( 'card_not_found', __( 'Card not found in the catalog.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}
		$id           = sanitize_text_field( (string) ( $card['id'] ?? $id ) );
		$group_id     = absint( $card['group_id'] ?? 0 );
		$fallback_url = ! empty( $card['image_url'] ) ? esc_url_raw( (string) $card['image_url'] ) : '';
		if ( ! Card_Vault_Image_Cache::is_allowed_image_url( $fallback_url ) ) {
			$fallback_url = '';
		}

		// 2. Check if already cached
		$cached_url = Card_Vault_Image_Cache::get_cached_image_url( $id, $group_id, $thumb );
		$has_cached = ! empty( $cached_url );
		if ( $has_cached ) {
			if ( $redirect ) {
				wp_redirect( $cached_url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- server-built uploads URL
				exit;
			}
			return rest_ensure_response( array(
				'success'   => true,
				'cached'    => true,
				'imageUrl'  => $cached_url,
				'cardId'    => $id,
			) );
		}

		// 3. If uncached and no remote image URL found, return 404
		$is_url_missing = empty( $fallback_url );
		if ( $is_url_missing ) {
			return new WP_Error( 'image_not_found', __( 'No image source available for this card.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		// 4. Download and transcode to WebP
		$new_cached_url = Card_Vault_Image_Cache::cache_remote_image( $fallback_url, $id, $group_id, $thumb );
		$has_transcoded = ! empty( $new_cached_url );
		if ( $has_transcoded ) {
			if ( $redirect ) {
				wp_redirect( $new_cached_url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- server-built uploads URL
				exit;
			}
			return rest_ensure_response( array(
				'success'   => true,
				'cached'    => true,
				'imageUrl'  => $new_cached_url,
				'cardId'    => $id,
			) );
		}

		// 5. Fall back to the catalog's own (allowlisted) image URL if caching was not possible
		if ( $redirect ) {
			wp_redirect( $fallback_url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- allowlisted https catalog host only
			exit;
		}

		return rest_ensure_response( array(
			'success'   => true,
			'cached'    => false,
			'imageUrl'  => $fallback_url,
			'cardId'    => $id,
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

		// Catalog data only: collector inventory rows belong to their owners and are
		// never returned from this route.
		// Manufacturer UPC or Catalog ID Lookup
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
		$should_check = (bool) $request->get_param( 'check' ) && true === self::check_admin_permission( $request );
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

		$is_admin = true === self::check_admin_permission( $request );
		$status   = $is_admin ? false : get_transient( 'card_vault_catalog_status_public' );
		if ( ! is_array( $status ) ) {
			$status = Card_Vault_Catalog_DB::get_status();
			if ( ! $is_admin ) {
				// Short cache so anonymous callers cannot force repeated full-table aggregates.
				set_transient( 'card_vault_catalog_status_public', $status, 60 );
			}
		}
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
			// Same 50-set ceiling as batch_size, so one request cannot run every remaining set.
			$limit       = $request->get_param( 'limit' ) ? max( 1, min( 50, (int) $request->get_param( 'limit' ) ) ) : null;
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
		$force = (bool) $request->get_param( 'force' ) && true === self::check_admin_permission( $request );
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
		// Reading status never runs a crawl step. Self-heal of an overdue crawler is
		// limited to admins; everyone else gets a read-only snapshot.
		$auto_advance = $request->get_param( 'auto_advance' ) !== '0' && current_user_can( 'manage_options' );
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

	/**
	 * Retrieve disk cache usage metrics.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_cache_stats( WP_REST_Request $request ): WP_REST_Response {
		$stats = Card_Vault_Image_Cache::get_cache_stats();

		return rest_ensure_response( array(
			'success' => true,
			'stats'   => $stats,
		) );
	}

	/**
	 * Purge local image cache.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_cache_purge( WP_REST_Request $request ): WP_REST_Response {
		$result = Card_Vault_Image_Cache::purge_cache();

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Image cache purged.', 'xophz-compass-card-vault' ),
			'result'  => $result,
		) );
	}

	/**
	 * Get subsite selective catalog sync ingestion settings.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_get_sync_settings( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( array(
			'success'              => true,
			'selected_categories'  => Card_Vault_Catalog_DB::get_selected_categories(),
			'sets_scope'           => Card_Vault_Catalog_DB::get_sets_scope(),
			'available_categories' => Card_Vault_Catalog_Crawler::get_available_categories(),
		) );
	}

	/**
	 * Update subsite selective catalog sync ingestion settings.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_update_sync_settings( WP_REST_Request $request ): WP_REST_Response {
		if ( $request->get_param( 'category_ids' ) && is_array( $request->get_param( 'category_ids' ) ) ) {
			Card_Vault_Catalog_DB::set_selected_categories( $request->get_param( 'category_ids' ) );
		}
		if ( $request->get_param( 'sets_scope' ) ) {
			Card_Vault_Catalog_DB::set_sets_scope( (string) $request->get_param( 'sets_scope' ) );
		}

		return rest_ensure_response( array(
			'success'             => true,
			'message'             => __( 'Sync settings updated.', 'xophz-compass-card-vault' ),
			'selected_categories' => Card_Vault_Catalog_DB::get_selected_categories(),
			'sets_scope'          => Card_Vault_Catalog_DB::get_sets_scope(),
		) );
	}
}
