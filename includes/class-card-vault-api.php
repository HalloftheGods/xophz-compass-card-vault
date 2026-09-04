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
}
