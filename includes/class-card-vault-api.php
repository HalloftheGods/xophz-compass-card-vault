<?php
/**
 * REST API Controller for My Card Vault.
 * Provides endpoints for card scanning, grading, catalog search, offline delta sync, and consignor portal.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'xophz-card-vault/v1';

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// 1. Vision Card Scanning
		register_rest_route( self::NAMESPACE, '/scan-card', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_scan_card' ),
			'permission_callback' => '__return_true',
		) );

		// 2. Optical Grading & Defect Mapping
		register_rest_route( self::NAMESPACE, '/grade-card', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_grade_card' ),
			'permission_callback' => '__return_true',
		) );

		// 3. Master Catalog Search
		register_rest_route( self::NAMESPACE, '/pokemon/search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_pokemon_search' ),
			'permission_callback' => '__return_true',
		) );

		// 4. Offline Delta Sync & Auto-Delist
		register_rest_route( self::NAMESPACE, '/sync/delta', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_sync_delta' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		// 5. Scoped Consignor Dashboard Data
		register_rest_route( self::NAMESPACE, '/consignor/dashboard', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_consignor_dashboard' ),
			'permission_callback' => array( $this, 'check_consignor_or_dealer_permission' ),
		) );

		// 6. Dealer Portal Aggregate Summary
		register_rest_route( self::NAMESPACE, '/dealer/summary', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_dealer_summary' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		// 7. Consignors List & Creation
		register_rest_route( self::NAMESPACE, '/consignors', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_consignors' ),
				'permission_callback' => array( $this, 'check_dealer_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create_consignor' ),
				'permission_callback' => array( $this, 'check_dealer_permission' ),
			),
		) );

		// 8. Payouts Ledger & Settlement
		register_rest_route( self::NAMESPACE, '/payouts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_payouts' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/payouts/settle', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_settle_payout' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		// 9. Community Collector Collection
		register_rest_route( self::NAMESPACE, '/collector/collection', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_collector_collection' ),
			'permission_callback' => array( $this, 'check_authenticated_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/collector/collection/sync', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_sync_collector_collection' ),
			'permission_callback' => array( $this, 'check_authenticated_permission' ),
		) );

		// 10. Consignment Intake Pipeline
		register_rest_route( self::NAMESPACE, '/intake/submit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_submit_intake' ),
			'permission_callback' => array( $this, 'check_authenticated_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/intake/batches', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_intake_batches' ),
			'permission_callback' => array( $this, 'check_authenticated_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/intake/appraise', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_appraise_intake' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/intake/accept', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_accept_intake' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		// 11. Card Show Public Showcase & Vendor Bids
		register_rest_route( self::NAMESPACE, '/showcase/(?P<slug>[a-zA-Z0-9_-]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_showcase' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/showcase/(?P<slug>[a-zA-Z0-9_-]+)/bid', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_submit_show_bid' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/collector/bids', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_collector_bids' ),
			'permission_callback' => array( $this, 'check_authenticated_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/collector/bids/(?P<id>\d+)/action', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_action_collector_bid' ),
			'permission_callback' => array( $this, 'check_authenticated_permission' ),
		) );

		// 12. Community Settings
		register_rest_route( self::NAMESPACE, '/settings/community', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_community_settings' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_update_community_settings' ),
				'permission_callback' => array( $this, 'check_dealer_permission' ),
			),
		) );

		// 13. POS Checkout & Stripe Connectors
		register_rest_route( self::NAMESPACE, '/pos/config', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_get_pos_config' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/pos/checkout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_pos_checkout' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/pos/verify-payment', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_pos_verify_payment' ),
			'permission_callback' => '__return_true',
		) );

		// 14. Shop Products (Bazaar WooCommerce Parity)
		register_rest_route( self::NAMESPACE, '/products', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_products' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_save_product' ),
				'permission_callback' => array( $this, 'check_dealer_permission' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/products/stock', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_update_product_stock' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/products/lookup-barcode', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_lookup_barcode' ),
			'permission_callback' => array( $this, 'check_dealer_permission' ),
		) );

		// 15. Card Vault License Checkout Session (Bazaar Integration)
		register_rest_route( self::NAMESPACE, '/license/checkout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_license_checkout' ),
			'permission_callback' => '__return_true',
		) );

		// 16. Dedicated Authentication Endpoints (Bypasses wp-login.php Turnstile)
		register_rest_route( self::NAMESPACE, '/auth/login', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_auth_login' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/auth/logout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_auth_logout' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/auth/me', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'handle_auth_me' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Permission check for authenticated users.
	 *
	 * @return bool
	 */
	public function check_authenticated_permission() {
		return is_user_logged_in() && get_current_user_id() > 0;
	}

	/**
	 * Permission check for dealer operations.
	 *
	 * @return bool
	 */
	public function check_dealer_permission() {
		return current_user_can( 'manage_card_vault' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Permission check for consignor dashboard.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_consignor_or_dealer_permission( $request ) {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return false;
		}

		if ( current_user_can( 'manage_card_vault' ) || current_user_can( 'manage_options' ) || current_user_can( 'view_consignor_dashboard' ) ) {
			return true;
		}

		return in_array( 'card_vault_consignor', (array) $user->roles, true );
	}

	/**
	 * Handle POST /scan-card: Vision model card identification.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_scan_card( $request ) {
		$params = $request->get_json_params();
		$image_b64 = $params['imageBase64'] ?? '';
		$mime_type = $params['mimeType'] ?? 'image/jpeg';

		if ( empty( $image_b64 ) ) {
			return new WP_REST_Response( array( 'error' => 'No image data provided.' ), 400 );
		}

		// Strip data URI header if present
		if ( strpos( $image_b64, 'base64,' ) !== false ) {
			$parts     = explode( 'base64,', $image_b64 );
			$image_b64 = $parts[1];
		}

		$system_instruction = 'You are an expert Pokemon card authenticator and cataloger for My Card Vault. Inspect the provided card photo and extract: cardName, setName, cardNumber, rarity, finish (Holo, Reverse Holo, Non-Holo), estimatedCondition (NM, LP, MP, HP, DMG), confidence (number 0-1), and visualNotes. Output valid JSON matching this schema exactly.';

		$prompt_parts = array(
			array(
				'inlineData' => array(
					'mimeType' => $mime_type,
					'data'     => $image_b64,
				),
			),
			array(
				'text' => 'Identify this Pokemon card. Return JSON with keys: cardName, setName, cardNumber, rarity, finish, estimatedCondition, confidence, visualNotes.',
			),
		);

		$result = Card_Vault_Gemini::generate_content( $prompt_parts, $system_instruction );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'error'   => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			), 500 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle POST /grade-card: Optical grading evaluation, defect mapping, subgrades, and ROI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_grade_card( $request ) {
		$params = $request->get_json_params();

		$front_image = $params['frontImage'] ?? '';
		$back_image  = $params['backImage'] ?? '';
		$card_name   = $params['cardName'] ?? 'Pokemon Card';
		$set_name    = $params['setName'] ?? '';
		$card_number = $params['cardNumber'] ?? '';
		$raw_price   = isset( $params['rawMarketPrice'] ) ? floatval( $params['rawMarketPrice'] ) : 0.00;
		$psa10_price = isset( $params['psa10Price'] ) ? floatval( $params['psa10Price'] ) : 0.00;
		$psa9_price  = isset( $params['psa9Price'] ) ? floatval( $params['psa9Price'] ) : 0.00;

		if ( empty( $front_image ) ) {
			return new WP_REST_Response( array( 'error' => 'Front card image is required for optical grading.' ), 400 );
		}

		$parts = array();

		// Clean front image
		$clean_front = $front_image;
		if ( strpos( $clean_front, 'base64,' ) !== false ) {
			$clean_front = explode( 'base64,', $clean_front )[1];
		}
		$parts[] = array(
			'inlineData' => array(
				'mimeType' => 'image/jpeg',
				'data'     => $clean_front,
			),
		);

		if ( ! empty( $back_image ) ) {
			$clean_back = $back_image;
			if ( strpos( $clean_back, 'base64,' ) !== false ) {
				$clean_back = explode( 'base64,', $clean_back )[1];
			}
			$parts[] = array(
				'inlineData' => array(
					'mimeType' => 'image/jpeg',
					'data'     => $clean_back,
				),
			);
		}

		$system_instruction = 'You are an optical trading card grading engine applying PSA, BGS, and CGC standards. Analyze the images for: centering, corners, edges, and surface. Predict an integer grade 1-10, gradeLabel (e.g. Gem Mint 10, Mint 9, Near Mint-Mint 8), subgrades (centering, corners, edges, surface), defects array (with id, title, category, severity [minor, moderate, severe], xPercent [0-100], yPercent [0-100], notes), recommendation (SUBMIT_PSA_GEM_10, SUBMIT_BGS_TRUE_GEM, or SELL_AS_NM_RAW), and overallSummary.';

		$prompt_text = sprintf(
			'Perform optical grading for %s (%s #%s). Raw market price: $%0.2f, PSA 10 price: $%0.2f, PSA 9 price: $%0.2f. Output strict JSON with: predictedGrade, gradeLabel, subgrades, defects, roi, overallSummary.',
			$card_name,
			$set_name,
			$card_number,
			$raw_price,
			$psa10_price,
			$psa9_price
		);
		$parts[] = array( 'text' => $prompt_text );

		$result = Card_Vault_Gemini::generate_content( $parts, $system_instruction );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'error' => $result->get_error_message(),
				'code'  => $result->get_error_code(),
			), 500 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle GET /pokemon/search: Master Pokémon catalog query without creating empty WooCommerce products.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_pokemon_search( $request ) {
		$q      = strtolower( trim( $request->get_param( 'q' ) ?: '' ) );
		$set    = strtolower( trim( $request->get_param( 'set' ) ?: '' ) );
		$rarity = strtolower( trim( $request->get_param( 'rarity' ) ?: '' ) );
		$page   = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit  = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );

		$catalog_file = XOPHZ_COMPASS_CARD_VAULT_PATH . 'includes/data/pokemon-catalog.json';
		$cards = array();

		if ( file_exists( $catalog_file ) ) {
			$raw_data = file_get_contents( $catalog_file );
			$cards    = json_decode( $raw_data, true ) ?: array();
		}

		$filtered = array();
		foreach ( $cards as $card ) {
			if ( ! empty( $q ) ) {
				$name_match   = strpos( strtolower( $card['name'] ?? '' ), $q ) !== false;
				$number_match = strpos( strtolower( $card['number'] ?? '' ), $q ) !== false;
				$id_match     = strpos( strtolower( $card['id'] ?? '' ), $q ) !== false;
				if ( ! $name_match && ! $number_match && ! $id_match ) {
					continue;
				}
			}

			if ( ! empty( $set ) && strtolower( $card['setName'] ?? '' ) !== $set ) {
				continue;
			}

			if ( ! empty( $rarity ) && strtolower( $card['rarity'] ?? '' ) !== $rarity ) {
				continue;
			}

			$filtered[] = $card;
		}

		$total = count( $filtered );
		$paged = array_slice( $filtered, ( $page - 1 ) * $limit, $limit );

		return new WP_REST_Response( array(
			'count' => $total,
			'cards' => $paged,
		), 200 );
	}

	/**
	 * Handle POST /sync/delta: Offline queue synchronization and WooCommerce auto-delist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_sync_delta( $request ) {
		$params            = $request->get_json_params();
		$sales             = $params['sales'] ?? array();
		$inventory_updates = $params['inventoryUpdates'] ?? array();

		$synced_sales      = 0;
		$synced_inventory  = 0;
		$delisted_products = array();
		$errors            = array();

		// 1. Process sales deltas (stock deductions, auto-delisting, consignment payouts)
		if ( ! empty( $sales ) && is_array( $sales ) ) {
			$sales_result = Card_Vault_WC_Sync::process_sales_delta( $sales );
			$synced_sales = $sales_result['synced_sales'];
			$delisted_products = $sales_result['delisted_products'];
			if ( ! empty( $sales_result['errors'] ) ) {
				$errors = array_merge( $errors, $sales_result['errors'] );
			}
		}

		// 2. Process inventory updates (new acquisitions or restocks pushed to WooCommerce)
		if ( ! empty( $inventory_updates ) && is_array( $inventory_updates ) ) {
			foreach ( $inventory_updates as $item ) {
				$result = Card_Vault_WC_Sync::sync_inventory_item( $item );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					$synced_inventory++;
				}
			}
		}

		return new WP_REST_Response( array(
			'success'          => empty( $errors ),
			'syncedSales'      => $synced_sales,
			'syncedInventory'  => $synced_inventory,
			'delistedProducts' => $delisted_products,
			'errors'           => $errors,
		), 200 );
	}

	/**
	 * Handle GET /consignor/dashboard: Scoped consignor data endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_consignor_dashboard( $request ) {
		$user      = wp_get_current_user();
		$is_dealer = current_user_can( 'manage_card_vault' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		$requested_id = $request->get_param( 'consignor_id' );

		if ( $is_dealer && ! empty( $requested_id ) ) {
			$consignor = Card_Vault_Consignments::get_consignor( $requested_id );
		} else {
			// Find consignor linked to current user
			$consignor = Card_Vault_Consignments::get_consignor_by_user_id( $user->ID );
			if ( ! $consignor && ! empty( $user->user_email ) ) {
				$matches = Card_Vault_Consignments::get_consignors( array( 'search' => $user->user_email ) );
				if ( ! empty( $matches ) ) {
					$consignor = $matches[0];
				}
			}
		}

		if ( ! $consignor ) {
			return new WP_REST_Response( array(
				'consignor'       => null,
				'summary'         => null,
				'activeInventory' => array(),
				'sales'           => array(),
				'message'         => 'No linked consignor profile found.',
			), 200 );
		}

		$consignor_id = $consignor['consignor_id'];
		$summary      = Card_Vault_Consignments::get_consignor_summary( $consignor_id );
		$inventory    = Card_Vault_WC_Sync::get_consignor_active_inventory( $consignor_id );
		$payouts      = Card_Vault_Consignments::get_payouts( array(
			'consignor_id' => $consignor_id,
			'limit'        => 100,
		) );

		return new WP_REST_Response( array(
			'consignor'       => $consignor,
			'summary'         => $summary,
			'activeInventory' => $inventory,
			'sales'           => $payouts,
		), 200 );
	}

	/**
	 * Handle GET /dealer/summary.
	 */
	public function handle_dealer_summary( $request ) {
		$summary = Card_Vault_Consignments::get_dealer_aggregate_summary();
		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $summary,
		), 200 );
	}

	/**
	 * Handle GET /consignors.
	 */
	public function handle_get_consignors( $request ) {
		$status = $request->get_param( 'status' ) ?? '';
		$search = $request->get_param( 'search' ) ?? '';
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 50 );

		$consignors = Card_Vault_Consignments::get_consignors( array(
			'status' => $status,
			'search' => $search,
			'limit'  => $limit,
		) );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $consignors,
		), 200 );
	}

	/**
	 * Handle POST /consignors.
	 */
	public function handle_create_consignor( $request ) {
		$params = $request->get_json_params();
		$result = Card_Vault_Consignments::create_consignor( $params );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => $result->get_error_message(),
			), 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
		), 201 );
	}

	/**
	 * Handle GET /payouts.
	 */
	public function handle_get_payouts( $request ) {
		$status       = $request->get_param( 'payout_status' ) ?? '';
		$consignor_id = $request->get_param( 'consignor_id' ) ?? '';
		$limit        = (int) ( $request->get_param( 'limit' ) ?? 100 );

		$args = array( 'limit' => $limit );
		if ( ! empty( $status ) ) {
			$args['payout_status'] = $status;
		}
		if ( ! empty( $consignor_id ) ) {
			$args['consignor_id'] = $consignor_id;
		}

		$payouts = Card_Vault_Consignments::get_payouts( $args );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $payouts,
		), 200 );
	}

	/**
	 * Handle POST /payouts/settle.
	 */
	public function handle_settle_payout( $request ) {
		$params            = $request->get_json_params();
		$payout_id         = $params['payout_id'] ?? 0;
		$payment_method    = $params['payment_method'] ?? 'Cash';
		$payment_reference = $params['payment_reference'] ?? '';

		if ( empty( $payout_id ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Missing payout identifier.',
			), 400 );
		}

		$success = Card_Vault_Consignments::mark_payout_paid( $payout_id, $payment_method, $payment_reference );

		return new WP_REST_Response( array(
			'success' => $success,
		), $success ? 200 : 400 );
	}

	/**
	 * Handle GET /collector/collection.
	 */
	public function handle_get_collector_collection( $request ) {
		$user_id = get_current_user_id();
		$items = Card_Vault_Community::get_collector_items( $user_id );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $items,
			'meta'    => array(
				'timestamp' => time(),
				'version'   => defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ? XOPHZ_COMPASS_CARD_VAULT_VERSION : '1.0.0',
			),
		), 200 );
	}

	/**
	 * Handle POST /collector/collection/sync.
	 */
	public function handle_sync_collector_collection( $request ) {
		$user_id = get_current_user_id();
		$params = $request->get_json_params();
		$items = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();

		$success = Card_Vault_Community::sync_collector_items( $user_id, $items );

		return new WP_REST_Response( array(
			'success' => $success,
			'meta'    => array(
				'timestamp' => time(),
				'count'     => count( $items ),
			),
		), $success ? 200 : 400 );
	}

	/**
	 * Handle POST /intake/submit.
	 */
	public function handle_submit_intake( $request ) {
		$user_id = get_current_user_id();
		$params = $request->get_json_params();

		$result = Card_Vault_Community::submit_intake_batch( $user_id, $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
			'meta'    => array(
				'timestamp' => time(),
			),
		), 201 );
	}

	/**
	 * Handle GET /intake/batches.
	 */
	public function handle_get_intake_batches( $request ) {
		$user_id = get_current_user_id();
		$is_dealer = $this->check_dealer_permission();

		$args = array();
		// If not dealer, scope strictly to user's batches
		if ( ! $is_dealer ) {
			$args['wp_user_id'] = $user_id;
		} elseif ( $request->get_param( 'collector_id' ) ) {
			$args['wp_user_id'] = (int) $request->get_param( 'collector_id' );
		}

		if ( $request->get_param( 'status' ) ) {
			$args['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}

		if ( $request->get_param( 'batch_code' ) ) {
			$args['batch_code'] = sanitize_text_field( $request->get_param( 'batch_code' ) );
		}

		$batches = Card_Vault_Community::get_intake_batches( $args );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $batches,
			'meta'    => array(
				'timestamp' => time(),
				'count'     => count( $batches ),
			),
		), 200 );
	}

	/**
	 * Handle POST /intake/appraise.
	 */
	public function handle_appraise_intake( $request ) {
		$params = $request->get_json_params();
		$batch_id = isset( $params['batchId'] ) ? (int) $params['batchId'] : 0;

		if ( ! $batch_id ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Missing batchId.' ), 400 );
		}

		$result = Card_Vault_Community::appraise_intake_batch( $batch_id, $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => array( 'batchId' => $batch_id, 'appraised' => true ),
		), 200 );
	}

	/**
	 * Handle POST /intake/accept.
	 */
	public function handle_accept_intake( $request ) {
		$params = $request->get_json_params();
		$batch_id = isset( $params['batchId'] ) ? (int) $params['batchId'] : 0;

		if ( ! $batch_id ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Missing batchId.' ), 400 );
		}

		$result = Card_Vault_Community::accept_intake_batch( $batch_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
		), 200 );
	}

	/**
	 * Handle GET /showcase/:slug.
	 */
	public function handle_get_showcase( $request ) {
		$slug = $request->get_param( 'slug' );
		if ( empty( $slug ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Showcase slug required.' ), 400 );
		}

		$data = Card_Vault_Community::get_public_showcase( $slug );
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $data->get_error_message(),
			), $data->get_error_data()['status'] ?? 404 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $data,
			'meta'    => array(
				'timestamp' => time(),
			),
		), 200 );
	}

	/**
	 * Handle POST /showcase/:slug/bid.
	 */
	public function handle_submit_show_bid( $request ) {
		$slug = $request->get_param( 'slug' );
		$params = $request->get_json_params();
		$params['showcaseSlug'] = $slug;

		$result = Card_Vault_Community::submit_show_bid( $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
		), 201 );
	}

	/**
	 * Handle GET /collector/bids.
	 */
	public function handle_get_collector_bids( $request ) {
		$user_id = get_current_user_id();
		$bids = Card_Vault_Community::get_bids_for_collector( $user_id );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $bids,
			'meta'    => array(
				'timestamp' => time(),
				'count'     => count( $bids ),
			),
		), 200 );
	}

	/**
	 * Handle POST /collector/bids/:id/action.
	 */
	public function handle_action_collector_bid( $request ) {
		$user_id = get_current_user_id();
		$bid_id = (int) $request->get_param( 'id' );
		$params = $request->get_json_params();
		$action = $params['action'] ?? '';
		$counter_amount = isset( $params['counterAmount'] ) ? (float) $params['counterAmount'] : null;

		$result = Card_Vault_Community::action_show_bid( $bid_id, $user_id, $action, $counter_amount );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => array( 'bidId' => $bid_id, 'action' => $action ),
		), 200 );
	}

	/**
	 * Handle GET /settings/community.
	 */
	public function handle_get_community_settings( $request ) {
		$settings = Card_Vault_Community::get_settings();

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $settings,
		), 200 );
	}

	/**
	 * Handle POST /settings/community.
	 */
	public function handle_update_community_settings( $request ) {
		$params = $request->get_json_params();
		$updated = Card_Vault_Community::update_settings( $params );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $updated,
		), 200 );
	}

	/**
	 * Handle GET /pos/config.
	 */
	public function handle_get_pos_config( $request ) {
		$is_configured   = Card_Vault_Stripe::is_stripe_configured();
		$publishable_key = Card_Vault_Stripe::get_publishable_key();
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => array(
				'isStripeConfigured' => $is_configured,
				'publishableKey'     => $publishable_key,
				'currencySymbol'     => $currency_symbol,
				'siteName'           => get_bloginfo( 'name' ),
			),
			'meta'    => array(
				'timestamp' => time(),
				'version'   => defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ? XOPHZ_COMPASS_CARD_VAULT_VERSION : '1.0.0',
			),
		), 200 );
	}

	/**
	 * Handle POST /pos/checkout.
	 */
	public function handle_pos_checkout( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'Missing checkout payload.',
			), 400 );
		}

		$result = Card_Vault_Stripe::create_pos_checkout( $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
			'meta'    => array(
				'timestamp' => time(),
				'version'   => defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ? XOPHZ_COMPASS_CARD_VAULT_VERSION : '1.0.0',
			),
		), 201 );
	}

	/**
	 * Handle POST /pos/verify-payment.
	 */
	public function handle_pos_verify_payment( $request ) {
		$params     = $request->get_json_params();
		$order_id   = isset( $params['orderId'] ) ? intval( $params['orderId'] ) : 0;
		$session_id = isset( $params['sessionId'] ) ? sanitize_text_field( $params['sessionId'] ) : '';

		if ( ! $order_id || empty( $session_id ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'orderId and sessionId are required.',
			), 400 );
		}

		$result = Card_Vault_Stripe::verify_payment( $order_id, $session_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
			'meta'    => array(
				'timestamp' => time(),
			),
		), 200 );
	}

	/**
	 * Handle GET /products.
	 */
	public function handle_get_products( $request ) {
		$args = array(
			'page'     => $request->get_param( 'page' ) ?: 1,
			'limit'    => $request->get_param( 'limit' ) ?: 24,
			'search'   => $request->get_param( 'search' ) ?: '',
			'category' => $request->get_param( 'category' ) ?: '',
			'status'   => $request->get_param( 'status' ) ?: '',
		);

		$data = Card_Vault_Products::get_products( $args );

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $data['products'],
			'total'   => $data['total'],
			'meta'    => array(
				'timestamp' => time(),
				'version'   => defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ? XOPHZ_COMPASS_CARD_VAULT_VERSION : '1.0.0',
			),
		), 200 );
	}

	/**
	 * Handle POST /products.
	 */
	public function handle_save_product( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'Product data payload required.',
			), 400 );
		}

		$result = Card_Vault_Products::save_product( $params );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
			'meta'    => array(
				'timestamp' => time(),
			),
		), 200 );
	}

	/**
	 * Handle POST /products/stock.
	 */
	public function handle_update_product_stock( $request ) {
		$params     = $request->get_json_params();
		$product_id = isset( $params['productId'] ) ? intval( $params['productId'] ) : 0;
		$quantity   = isset( $params['quantity'] ) ? intval( $params['quantity'] ) : 0;
		$action     = isset( $params['action'] ) ? sanitize_text_field( $params['action'] ) : 'set';

		if ( ! $product_id ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'productId is required.',
			), 400 );
		}

		$result = Card_Vault_Products::update_product_stock( $product_id, $quantity, $action );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
			'meta'    => array(
				'timestamp' => time(),
			),
		), 200 );
	}

	/**
	 * Handle POST /products/lookup-barcode.
	 */
	public function handle_lookup_barcode( $request ) {
		$params  = $request->get_json_params();
		$barcode = isset( $params['barcode'] ) ? sanitize_text_field( $params['barcode'] ) : '';

		if ( empty( $barcode ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'Barcode is required.',
			), 400 );
		}

		$result = Card_Vault_Products::lookup_barcode( $barcode );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $result->get_error_message(),
			), $result->get_error_data()['status'] ?? 404 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $result,
			'meta'    => array(
				'timestamp' => time(),
			),
		), 200 );
	}

	/**
	 * Handle Card Vault license checkout session generation via Bazaar Checkout Service.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_license_checkout( $request ) {
		$params  = $request->get_json_params() ?: array();
		$tier    = sanitize_key( $params['tier'] ?? 'single' );
		$billing = sanitize_key( $params['billing'] ?? 'annual' );
		$return_url = ! empty( $params['return_url'] ) ? esc_url_raw( $params['return_url'] ) : '';

		$trial_days = 0;
		if ( $billing === 'annual' ) {
			if ( isset( $params['trial_days'] ) ) {
				$trial_days = intval( $params['trial_days'] );
			} elseif ( $tier === 'single' ) {
				$trial_days = 3;
			} elseif ( $tier === 'team' ) {
				$trial_days = 7;
			}
		}

		$target_slug  = "card-vault/{$tier}-{$billing}";
		$query_params = array(
			'billing'    => $billing,
			'return_url' => $return_url,
			'trial_days' => $trial_days,
		);

		if ( class_exists( 'Xophz_Bazaar_Checkout_Service' ) ) {
			$result = Xophz_Bazaar_Checkout_Service::process_buy_request( array( 'card-vault', "{$tier}-{$billing}" ), $query_params, 'POST' );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'error'   => $result->get_error_message(),
				), $result->get_error_data()['status'] ?? 400 );
			}

			return new WP_REST_Response( array(
				'success' => true,
				'url'     => $result['url'] ?? ( $result['checkout_url'] ?? '' ),
				'data'    => $result,
			), 200 );
		}

		$fallback_url = home_url( "/buy/{$target_slug}" );
		if ( $trial_days > 0 ) {
			$fallback_url = add_query_arg( 'trial_days', $trial_days, $fallback_url );
		}
		if ( ! empty( $return_url ) ) {
			$fallback_url = add_query_arg( 'return_url', rawurlencode( $return_url ), $fallback_url );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'url'     => $fallback_url,
			'data'    => array(
				'url'          => $fallback_url,
				'checkout_url' => $fallback_url,
			),
		), 200 );
	}

	/**
	 * Handle POST /auth/login.
	 * Authenticates user credentials directly via WordPress without triggering wp-login.php Turnstile captcha.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_auth_login( $request ) {
		$params   = $request->get_json_params();
		$username = isset( $params['username'] ) ? trim( (string) $params['username'] ) : '';
		$password = isset( $params['password'] ) ? (string) $params['password'] : '';
		$remember = ! empty( $params['remember'] );

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Username and password are required.', 'xophz-compass-card-vault' ),
			), 400 );
		}

		// Support email or username lookup
		$auth_login = $username;
		if ( is_email( $username ) ) {
			$user_by_email = get_user_by( 'email', $username );
			if ( $user_by_email ) {
				$auth_login = $user_by_email->user_login;
			}
		}

		// Direct username and password authentication bypassing wp-login.php Turnstile captcha
		$user = wp_authenticate_username_password( null, $auth_login, $password );

		if ( is_wp_error( $user ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Invalid credentials. Please check your username and password.', 'xophz-compass-card-vault' ),
			), 401 );
		}

		// Establish logged-in WordPress session
		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		// Determine user role and linked consignor
		$user_id   = $user->ID;
		$roles     = (array) $user->roles;
		$is_dealer = user_can( $user, 'manage_options' ) || user_can( $user, 'manage_card_vault' ) || in_array( 'shop_manager', $roles, true );
		$consignor = class_exists( 'Card_Vault_Consignments' ) ? Card_Vault_Consignments::get_consignor_by_user_id( $user_id ) : null;

		$role = 'collector';
		if ( $is_dealer ) {
			$role = 'dealer';
		} elseif ( in_array( 'card_vault_consignor', $roles, true ) ) {
			$role = 'consignor';
		}

		return new WP_REST_Response( array(
			'success' => true,
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'user'    => array(
				'id'          => $user_id,
				'userLogin'   => $user->user_login,
				'displayName' => $user->display_name,
				'email'       => $user->user_email,
				'role'        => $role,
				'consignorId' => $consignor ? $consignor['consignor_id'] : null,
			),
		), 200 );
	}

	/**
	 * Handle POST /auth/logout.
	 * Terminates the active WordPress session.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_auth_logout() {
		wp_logout();

		return new WP_REST_Response( array(
			'success' => true,
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		), 200 );
	}

	/**
	 * Handle GET /auth/me.
	 * Retrieves current authenticated user state and fresh REST nonce.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_auth_me() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_REST_Response( array(
				'success'    => true,
				'isLoggedIn' => false,
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'user'       => null,
			), 200 );
		}

		$user      = wp_get_current_user();
		$roles     = (array) $user->roles;
		$is_dealer = user_can( $user, 'manage_options' ) || user_can( $user, 'manage_card_vault' ) || in_array( 'shop_manager', $roles, true );
		$consignor = class_exists( 'Card_Vault_Consignments' ) ? Card_Vault_Consignments::get_consignor_by_user_id( $user_id ) : null;

		$role = 'collector';
		if ( $is_dealer ) {
			$role = 'dealer';
		} elseif ( in_array( 'card_vault_consignor', $roles, true ) ) {
			$role = 'consignor';
		}

		return new WP_REST_Response( array(
			'success'    => true,
			'isLoggedIn' => true,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'user'       => array(
				'id'          => $user_id,
				'userLogin'   => $user->user_login,
				'displayName' => $user->display_name,
				'email'       => $user->user_email,
				'role'        => $role,
				'consignorId' => $consignor ? $consignor['consignor_id'] : null,
			),
		), 200 );
	}
}
