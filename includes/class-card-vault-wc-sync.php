<?php
/**
 * WooCommerce Product CPT synchronization and auto-delist protection.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_WC_Sync {

	/**
	 * Meta keys used for synchronizing Card Vault inventory with WooCommerce products.
	 */
	const META_VAULT_ID             = '_card_vault_id';
	const META_CARD_ID              = '_card_vault_card_id';
	const META_SET_NAME             = '_card_vault_set_name';
	const META_SET_CODE             = '_card_vault_set_code';
	const META_CARD_NUMBER          = '_card_vault_card_number';
	const META_RARITY               = '_card_vault_rarity';
	const META_CONDITION            = '_card_vault_condition';
	const META_IS_FOIL              = '_card_vault_is_foil';
	const META_IS_GRADED            = '_card_vault_is_graded';
	const META_GRADE_LABEL          = '_card_vault_grade_label';
	const META_CERT_NUMBER          = '_card_vault_cert_number';
	const META_CONSIGNOR_ID         = '_card_vault_consignor_id';
	const META_CONSIGNOR_SPLIT_RATE = '_card_vault_consignor_split_rate';
	const META_ACQUIRED_PRICE       = '_card_vault_acquired_price';
	const META_ASKING_PRICE         = '_card_vault_asking_price';
	const META_AI_GRADE_REPORT      = '_card_vault_ai_grade_report';
	const META_IMAGE_URL            = '_card_vault_image_url';

	/**
	 * Initialize WooCommerce order completion hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_order_completed' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'handle_order_completed' ), 10, 1 );
	}

	/**
	 * Check whether WooCommerce is installed and active.
	 *
	 * @return bool
	 */
	public static function is_wc_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Find a WooCommerce product ID by its Card Vault client inventory UUID.
	 *
	 * @param string $vault_id Client inventory item UUID.
	 * @return int Product ID or 0 if not found.
	 */
	public static function find_product_by_vault_id( $vault_id ) {
		if ( empty( $vault_id ) ) {
			return 0;
		}

		$posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => self::META_VAULT_ID,
					'value'   => sanitize_text_field( $vault_id ),
					'compare' => '=',
				),
			),
		) );

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Find a WooCommerce product ID by catalog card ID (e.g. sv3pt5-199).
	 *
	 * @param string $card_id Master catalog card ID.
	 * @return int Product ID or 0 if not found.
	 */
	public static function find_product_by_card_id( $card_id ) {
		if ( empty( $card_id ) ) {
			return 0;
		}

		$posts = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => self::META_CARD_ID,
					'value'   => sanitize_text_field( $card_id ),
					'compare' => '=',
				),
			),
		) );

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Synchronize an active inventory card to WooCommerce product CPT.
	 * Only active inventory items with quantity > 0 are published to prevent catalog bloat.
	 *
	 * @param array $item Inventory item details.
	 * @return int|WP_Error Product ID or WP_Error.
	 */
	public static function sync_inventory_item( $item ) {
		if ( ! self::is_wc_active() ) {
			return new WP_Error( 'wc_inactive', __( 'WooCommerce is not active.', 'xophz-compass-card-vault' ) );
		}

		if ( empty( $item['id'] ) ) {
			return new WP_Error( 'missing_vault_id', __( 'Inventory item ID is required.', 'xophz-compass-card-vault' ) );
		}

		$vault_id   = sanitize_text_field( $item['id'] );
		$product_id = self::find_product_by_vault_id( $vault_id );

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
		} else {
			$product = new WC_Product_Simple();
		}

		if ( ! $product ) {
			return new WP_Error( 'product_init_failed', __( 'Could not instantiate WooCommerce product.', 'xophz-compass-card-vault' ) );
		}

		// Generate descriptive title
		$card_name   = ! empty( $item['cardName'] ) ? sanitize_text_field( $item['cardName'] ) : 'Unknown Card';
		$set_name    = ! empty( $item['setName'] ) ? sanitize_text_field( $item['setName'] ) : '';
		$card_number = ! empty( $item['cardNumber'] ) ? sanitize_text_field( $item['cardNumber'] ) : '';
		$is_graded   = ! empty( $item['isGraded'] );
		$grade_label = ! empty( $item['gradeLabel'] ) ? sanitize_text_field( $item['gradeLabel'] ) : '';
		$condition   = ! empty( $item['condition'] ) ? sanitize_text_field( $item['condition'] ) : 'NM';

		$title_parts = array( $card_name );
		if ( ! empty( $set_name ) ) {
			$title_parts[] = $set_name;
		}
		if ( ! empty( $card_number ) ) {
			$title_parts[] = '#' . $card_number;
		}
		$title = implode( ' - ', $title_parts );

		if ( $is_graded && ! empty( $grade_label ) ) {
			$title .= ' (' . $grade_label . ')';
		} elseif ( ! empty( $condition ) ) {
			$title .= ' [' . $condition . ']';
		}

		$product->set_name( $title );

		// Set unique SKU
		$card_id_slug = sanitize_title( $item['cardId'] ?? 'card' );
		$sku_suffix   = substr( md5( $vault_id ), 0, 6 );
		$sku          = 'CV-' . $card_id_slug . '-' . $sku_suffix;
		$product->set_sku( $sku );

		// Prices
		$asking_price = isset( $item['askingPrice'] ) ? floatval( $item['askingPrice'] ) : 0.00;
		$product->set_regular_price( wc_format_decimal( $asking_price ) );
		$product->set_price( wc_format_decimal( $asking_price ) );

		// Manage Stock
		$quantity = isset( $item['quantity'] ) ? max( 0, (int) $item['quantity'] ) : 1;
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );

		if ( $quantity > 0 ) {
			$product->set_stock_status( 'instock' );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
		} else {
			// Auto-delist if out of stock
			$product->set_stock_status( 'outofstock' );
			$product->set_status( 'draft' );
			$product->set_catalog_visibility( 'hidden' );
		}

		// Set item descriptions
		$description = sprintf(
			"Card: %s\nSet: %s (%s)\nCondition: %s\nRarity: %s\nFoil: %s",
			$card_name,
			$set_name,
			$card_number,
			$condition,
			$item['rarity'] ?? 'Common',
			! empty( $item['isFoil'] ) ? 'Yes' : 'No'
		);
		if ( $is_graded && ! empty( $grade_label ) ) {
			$description .= sprintf( "\nGrading: %s (Cert: %s)", $grade_label, $item['certNumber'] ?? 'N/A' );
		}
		$product->set_description( $description );
		$product->set_short_description( $title );

		// Save product to obtain ID
		$saved_id = $product->save();
		if ( ! $saved_id ) {
			return new WP_Error( 'product_save_failed', __( 'Failed to save WooCommerce product.', 'xophz-compass-card-vault' ) );
		}

		// Store custom post metadata
		update_post_meta( $saved_id, self::META_VAULT_ID, $vault_id );
		update_post_meta( $saved_id, self::META_CARD_ID, sanitize_text_field( $item['cardId'] ?? '' ) );
		update_post_meta( $saved_id, self::META_SET_NAME, $set_name );
		update_post_meta( $saved_id, self::META_SET_CODE, sanitize_text_field( $item['setCode'] ?? '' ) );
		update_post_meta( $saved_id, self::META_CARD_NUMBER, $card_number );
		update_post_meta( $saved_id, self::META_RARITY, sanitize_text_field( $item['rarity'] ?? '' ) );
		update_post_meta( $saved_id, self::META_CONDITION, $condition );
		update_post_meta( $saved_id, self::META_IS_FOIL, ! empty( $item['isFoil'] ) ? 'yes' : 'no' );
		update_post_meta( $saved_id, self::META_IS_GRADED, $is_graded ? 'yes' : 'no' );
		update_post_meta( $saved_id, self::META_GRADE_LABEL, $grade_label );
		update_post_meta( $saved_id, self::META_CERT_NUMBER, sanitize_text_field( $item['certNumber'] ?? '' ) );
		update_post_meta( $saved_id, self::META_CONSIGNOR_ID, sanitize_text_field( $item['consignorId'] ?? '' ) );
		update_post_meta( $saved_id, self::META_CONSIGNOR_SPLIT_RATE, isset( $item['consignorSplitRate'] ) ? floatval( $item['consignorSplitRate'] ) : 85.00 );
		update_post_meta( $saved_id, self::META_ACQUIRED_PRICE, isset( $item['acquiredPrice'] ) ? floatval( $item['acquiredPrice'] ) : 0.00 );
		update_post_meta( $saved_id, self::META_ASKING_PRICE, $asking_price );

		if ( ! empty( $item['aiGradeReport'] ) ) {
			update_post_meta( $saved_id, self::META_AI_GRADE_REPORT, wp_json_encode( $item['aiGradeReport'] ) );
		}
		if ( ! empty( $item['imageUrl'] ) ) {
			update_post_meta( $saved_id, self::META_IMAGE_URL, esc_url_raw( $item['imageUrl'] ) );
		}

		return (int) $saved_id;
	}

	/**
	 * Auto-delist protection: Decrement stock and immediately transition to draft / outofstock if 0.
	 * Prevents double-selling cards online when sold at physical card shows.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @param int $quantity_sold Units sold.
	 * @return array
	 */
	public static function auto_delist_product( $product_id, $quantity_sold = 1 ) {
		if ( ! self::is_wc_active() ) {
			return array(
				'product_id' => $product_id,
				'new_stock'  => 0,
				'delisted'   => false,
				'error'      => 'WooCommerce inactive',
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'product_id' => $product_id,
				'new_stock'  => 0,
				'delisted'   => false,
				'error'      => 'Product not found',
			);
		}

		$current_stock = (int) $product->get_stock_quantity();
		$new_stock     = max( 0, $current_stock - (int) $quantity_sold );
		$product->set_stock_quantity( $new_stock );

		$delisted = false;
		if ( $new_stock <= 0 ) {
			$product->set_stock_status( 'outofstock' );
			$product->set_status( 'draft' );
			$product->set_catalog_visibility( 'hidden' );
			$delisted = true;
		}

		$product->save();

		return array(
			'product_id' => $product_id,
			'new_stock'  => $new_stock,
			'delisted'   => $delisted,
		);
	}

	/**
	 * Process a batch of sales deltas from offline POS queues or local transactions.
	 *
	 * @param array $sales Array of sale transactions.
	 * @return array Results summary with synced count and delisted product IDs.
	 */
	public static function process_sales_delta( $sales ) {
		$synced_sales      = 0;
		$delisted_products = array();
		$errors            = array();

		if ( ! is_array( $sales ) ) {
			return array(
				'synced_sales'      => 0,
				'delisted_products' => array(),
				'errors'            => array( 'Invalid sales payload format' ),
			);
		}

		foreach ( $sales as $sale ) {
			$inventory_item_id = $sale['inventoryItemId'] ?? ( $sale['inventory_item_id'] ?? '' );
			$card_id           = $sale['cardId'] ?? ( $sale['card_id'] ?? '' );
			$sale_record_id    = $sale['id'] ?? ( $sale['sale_record_id'] ?? '' );
			$quantity_sold     = isset( $sale['quantity'] ) ? max( 1, (int) $sale['quantity'] ) : 1;

			if ( empty( $sale_record_id ) ) {
				$errors[] = 'Missing sale record ID for transaction.';
				continue;
			}

			$product_id = 0;
			if ( ! empty( $inventory_item_id ) ) {
				$product_id = self::find_product_by_vault_id( $inventory_item_id );
			}
			if ( ! $product_id && ! empty( $card_id ) ) {
				$product_id = self::find_product_by_card_id( $card_id );
			}

			if ( $product_id > 0 ) {
				$delist_result = self::auto_delist_product( $product_id, $quantity_sold );
				if ( ! empty( $delist_result['delisted'] ) ) {
					$delisted_products[] = $product_id;
				}

				// Check if this card was consigned
				$consignor_id = get_post_meta( $product_id, self::META_CONSIGNOR_ID, true );
				if ( ! empty( $consignor_id ) ) {
					$split_rate = (float) get_post_meta( $product_id, self::META_CONSIGNOR_SPLIT_RATE, true ) ?: 85.00;
					$sale_price = isset( $sale['salePrice'] ) ? floatval( $sale['salePrice'] ) : ( (float) get_post_meta( $product_id, self::META_ASKING_PRICE, true ) );

					Card_Vault_Consignments::record_payout( array(
						'sale_record_id'          => $sale_record_id,
						'consignor_id'             => $consignor_id,
						'wc_product_id'            => $product_id,
						'inventory_item_id'        => $inventory_item_id,
						'card_id'                  => $card_id,
						'card_name'                => $sale['cardName'] ?? get_the_title( $product_id ),
						'set_name'                 => $sale['setName'] ?? get_post_meta( $product_id, self::META_SET_NAME, true ),
						'card_number'              => $sale['cardNumber'] ?? get_post_meta( $product_id, self::META_CARD_NUMBER, true ),
						'quantity'                 => $quantity_sold,
						'sale_price_per_unit'      => $sale_price,
						'total_sale_amount'        => $sale_price * $quantity_sold,
						'consignor_split_rate'     => $split_rate,
						'sale_source'              => $sale['saleSource'] ?? 'pos',
						'notes'                    => $sale['notes'] ?? 'Show floor offline transaction sync',
					) );
				}
			} elseif ( ! empty( $sale['consignorId'] ) ) {
				// Card was not in WC product table but consignor is tracked
				$split_rate = isset( $sale['consignorSplitRate'] ) ? floatval( $sale['consignorSplitRate'] ) : 85.00;
				$sale_price = isset( $sale['salePrice'] ) ? floatval( $sale['salePrice'] ) : 0.00;

				Card_Vault_Consignments::record_payout( array(
					'sale_record_id'          => $sale_record_id,
					'consignor_id'             => sanitize_text_field( $sale['consignorId'] ),
					'inventory_item_id'        => $inventory_item_id,
					'card_id'                  => $card_id,
					'card_name'                => sanitize_text_field( $sale['cardName'] ?? 'Unknown Card' ),
					'set_name'                 => sanitize_text_field( $sale['setName'] ?? '' ),
					'card_number'              => sanitize_text_field( $sale['cardNumber'] ?? '' ),
					'quantity'                 => $quantity_sold,
					'sale_price_per_unit'      => $sale_price,
					'total_sale_amount'        => $sale_price * $quantity_sold,
					'consignor_split_rate'     => $split_rate,
					'sale_source'              => $sale['saleSource'] ?? 'pos',
					'notes'                    => $sale['notes'] ?? 'Show floor sale record',
				) );
			}

			$synced_sales++;
		}

		return array(
			'synced_sales'      => $synced_sales,
			'delisted_products' => array_unique( $delisted_products ),
			'errors'            => $errors,
		);
	}

	/**
	 * Automatically credit consignor payouts when an order is completed in WooCommerce storefront or Bazaar POS.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public static function handle_order_completed( $order_id ) {
		if ( ! self::is_wc_active() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Determine sale source: web storefront vs Bazaar POS
		$is_bazaar = ! empty( $order->get_meta( '_pos_cashier_id' ) ) || ! empty( $order->get_meta( '_bazaar_order' ) );
		$source    = $is_bazaar ? 'bazaar_pos' : 'wc_storefront';

		$items_credited = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = $item->get_product_id();
			if ( ! $product_id ) {
				continue;
			}

			$consignor_id = get_post_meta( $product_id, self::META_CONSIGNOR_ID, true );
			if ( empty( $consignor_id ) ) {
				continue;
			}

			// Generate idempotent sale record identifier for this line item
			$sale_record_id = sprintf( 'wc_order_%d_item_%d', (int) $order_id, (int) $item_id );

			$line_total  = (float) $item->get_total();
			$quantity    = max( 1, (int) $item->get_quantity() );
			$unit_price  = $quantity > 0 ? ( $line_total / $quantity ) : $line_total;
			$split_rate  = (float) get_post_meta( $product_id, self::META_CONSIGNOR_SPLIT_RATE, true ) ?: 85.00;
			$card_name   = $item->get_name();
			$card_id     = get_post_meta( $product_id, self::META_CARD_ID, true ) ?: ( 'wc_' . $product_id );
			$vault_id    = get_post_meta( $product_id, self::META_VAULT_ID, true ) ?: '';

			$payout_id = Card_Vault_Consignments::record_payout( array(
				'sale_record_id'          => $sale_record_id,
				'consignor_id'             => $consignor_id,
				'wc_order_id'              => $order_id,
				'wc_product_id'            => $product_id,
				'inventory_item_id'        => $vault_id,
				'card_id'                  => $card_id,
				'card_name'                => $card_name,
				'set_name'                 => get_post_meta( $product_id, self::META_SET_NAME, true ),
				'card_number'              => get_post_meta( $product_id, self::META_CARD_NUMBER, true ),
				'quantity'                 => $quantity,
				'sale_price_per_unit'      => $unit_price,
				'total_sale_amount'        => $line_total,
				'consignor_split_rate'     => $split_rate,
				'sale_source'              => $source,
				'payout_status'            => 'unpaid',
				'notes'                    => sprintf( 'Credited automatically from %s Order #%d', ( $is_bazaar ? 'Bazaar POS' : 'Web Storefront' ), $order_id ),
			) );

			if ( ! is_wp_error( $payout_id ) ) {
				$items_credited++;
				$consignor_payout = round( $line_total * ( $split_rate / 100.0 ), 2 );
				$order->add_order_note( sprintf(
					'Card Vault: Consignor %s credited $%0.2f (%.2f%%) for %s.',
					$consignor_id,
					$consignor_payout,
					$split_rate,
					$card_name
				) );
			}
		}
	}

	/**
	 * Retrieve active consigned inventory cards formatted for the consignor dashboard.
	 * Wholesale costs basis is strictly omitted for security.
	 *
	 * @param string $consignor_id Consignor unique ID.
	 * @return array
	 */
	public static function get_consignor_active_inventory( $consignor_id ) {
		if ( empty( $consignor_id ) ) {
			return array();
		}

		$products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 100,
			'meta_query'     => array(
				array(
					'key'     => self::META_CONSIGNOR_ID,
					'value'   => sanitize_text_field( $consignor_id ),
					'compare' => '=',
				),
			),
		) );

		$inventory = array();
		foreach ( $products as $p ) {
			$product_id = $p->ID;
			$stock      = (int) get_post_meta( $product_id, '_stock', true );
			$stock_stat = get_post_meta( $product_id, '_stock_status', true ) ?: 'outofstock';

			$inventory[] = array(
				'productId'     => $product_id,
				'vaultId'       => get_post_meta( $product_id, self::META_VAULT_ID, true ),
				'cardId'        => get_post_meta( $product_id, self::META_CARD_ID, true ),
				'title'         => $p->post_title,
				'setName'       => get_post_meta( $product_id, self::META_SET_NAME, true ),
				'cardNumber'    => get_post_meta( $product_id, self::META_CARD_NUMBER, true ),
				'condition'     => get_post_meta( $product_id, self::META_CONDITION, true ),
				'isGraded'      => get_post_meta( $product_id, self::META_IS_GRADED, true ) === 'yes',
				'gradeLabel'    => get_post_meta( $product_id, self::META_GRADE_LABEL, true ),
				'certNumber'    => get_post_meta( $product_id, self::META_CERT_NUMBER, true ),
				'askingPrice'   => (float) get_post_meta( $product_id, self::META_ASKING_PRICE, true ),
				'quantity'      => $stock,
				'stockStatus'   => $stock_stat,
				'splitRate'     => (float) get_post_meta( $product_id, self::META_CONSIGNOR_SPLIT_RATE, true ),
				'imageUrl'      => get_post_meta( $product_id, self::META_IMAGE_URL, true ),
			);
		}

		return $inventory;
	}
}
