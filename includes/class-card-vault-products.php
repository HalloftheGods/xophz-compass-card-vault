<?php
/**
 * Bazaar-Compatible Product Backend Integration for Card Vault.
 *
 * Implements WooCommerce product management for card shops (singles,
 * sealed booster boxes, packs, supplies, accessories, and custom items)
 * using the exact architecture established in Xophz Bazaar.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Products {

	/**
	 * Meta key for storing product barcodes.
	 */
	const META_BARCODE      = '_card_vault_barcode';
	const META_PRODUCT_TYPE = '_card_vault_product_type';

	/**
	 * Largest decoded imageData accepted by save_product, in bytes.
	 */
	const MAX_IMAGE_BYTES = 5242880;

	/**
	 * Retrieve products using WooCommerce product queries.
	 *
	 * @param array $args Query parameters.
	 * @return array List of products and total count.
	 */
	public static function get_products( array $args = array() ): array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return array(
				'total'    => 0,
				'products' => array(),
			);
		}

		$page     = max( 1, intval( $args['page'] ?? 1 ) );
		$limit    = max( 1, min( 100, intval( $args['limit'] ?? 24 ) ) );
		$search   = sanitize_text_field( $args['search'] ?? '' );
		$category = sanitize_text_field( $args['category'] ?? '' );
		$status   = sanitize_text_field( $args['status'] ?? '' );

		// Only dealers may see or request non-published products.
		$is_dealer = current_user_can( 'manage_card_vault' ) || current_user_can( 'manage_woocommerce' );
		if ( ! $is_dealer ) {
			$status_query = 'publish';
		} else {
			$status_query = ! empty( $status ) ? $status : array( 'publish', 'draft' );
		}

		$query_args = array(
			'paginate' => true,
			'page'     => $page,
			'limit'    => $limit,
			'status'   => $status_query,
			'return'   => 'ids',
		);

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		if ( ! empty( $category ) ) {
			$query_args['category'] = array( $category );
		}

		$wc_results = wc_get_products( $query_args );
		$total      = $wc_results->total ?? 0;
		$ids        = $wc_results->products ?? array();

		$products = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}

			$products[] = self::format_product( $p );
		}

		return array(
			'total'    => $total,
			'products' => $products,
		);
	}

	/**
	 * Map a WooCommerce product to the Card Vault product shape.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	private static function format_product( $p ): array {
		$thumb_id  = $p->get_image_id();
		$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';

		return array(
			'id'            => $p->get_id(),
			'title'         => $p->get_name(),
			'sku'           => $p->get_sku(),
			'price'         => floatval( $p->get_price() ),
			'regularPrice'  => floatval( $p->get_regular_price() ),
			'salePrice'     => $p->get_sale_price() ? floatval( $p->get_sale_price() ) : null,
			'stock'         => $p->get_stock_quantity() !== null ? intval( $p->get_stock_quantity() ) : 0,
			'stockStatus'   => $p->get_stock_status(),
			'manageStock'   => $p->managing_stock(),
			'thumb'         => $thumb_url,
			'barcode'       => get_post_meta( $p->get_id(), self::META_BARCODE, true ) ?: '',
			'productType'   => get_post_meta( $p->get_id(), self::META_PRODUCT_TYPE, true ) ?: 'single',
			'categories'    => wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
			'vaultId'       => get_post_meta( $p->get_id(), Card_Vault_WC_Sync::META_VAULT_ID, true ) ?: '',
		);
	}

	/**
	 * Save or create a product using Bazaar WooCommerce conventions.
	 *
	 * @param array $payload Product details.
	 * @return array|WP_Error Saved product or WP_Error.
	 */
	public static function save_product( array $payload ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'wc_inactive', __( 'WooCommerce is required for product operations.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$product_id = isset( $payload['id'] ) && is_numeric( $payload['id'] ) ? intval( $payload['id'] ) : 0;
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : new WC_Product_Simple();

		if ( ! $product ) {
			return new WP_Error( 'product_not_found', __( 'Product not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		if ( isset( $payload['title'] ) && ! empty( $payload['title'] ) ) {
			$product->set_name( sanitize_text_field( $payload['title'] ) );
		}

		if ( isset( $payload['description'] ) ) {
			$product->set_description( wp_kses_post( $payload['description'] ) );
		}

		if ( isset( $payload['shortDescription'] ) ) {
			$product->set_short_description( wp_kses_post( $payload['shortDescription'] ) );
		}

		if ( isset( $payload['regularPrice'] ) && $payload['regularPrice'] !== '' ) {
			$product->set_regular_price( wc_format_decimal( $payload['regularPrice'] ) );
		}

		if ( isset( $payload['salePrice'] ) && $payload['salePrice'] !== '' ) {
			$product->set_sale_price( wc_format_decimal( $payload['salePrice'] ) );
		} elseif ( array_key_exists( 'salePrice', $payload ) ) {
			// Only an explicit empty/null salePrice clears the sale price.
			$product->set_sale_price( '' );
		}

		if ( isset( $payload['sku'] ) && ! empty( $payload['sku'] ) ) {
			try {
				$product->set_sku( sanitize_text_field( $payload['sku'] ) );
			} catch ( WC_Data_Exception $e ) {
				return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => 400 ) );
			}
		}

		if ( isset( $payload['manageStock'] ) ) {
			$manage = (bool) $payload['manageStock'];
			$product->set_manage_stock( $manage );
			if ( $manage && isset( $payload['stockQuantity'] ) ) {
				$product->set_stock_quantity( intval( $payload['stockQuantity'] ) );
			}
		}

		if ( isset( $payload['stockStatus'] ) ) {
			$product->set_stock_status( sanitize_text_field( $payload['stockStatus'] ) );
		}

		// Handle category assignments
		if ( isset( $payload['categoryIds'] ) && is_array( $payload['categoryIds'] ) ) {
			$product->set_category_ids( array_map( 'intval', $payload['categoryIds'] ) );
		}

		// Handle base64 image data upload matching Bazaar
		if ( ! empty( $payload['imageData'] ) ) {
			$clean_b64  = preg_replace( '#^data:image/\w+;base64,#i', '', (string) $payload['imageData'] );
			$image_raw  = base64_decode( $clean_b64, true );

			if ( false === $image_raw || '' === $image_raw || strlen( $image_raw ) > self::MAX_IMAGE_BYTES ) {
				return new WP_Error( 'invalid_image', __( 'imageData must be valid base64 and at most 5 MB.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
			}

			$image_info = getimagesizefromstring( $image_raw );
			$ext_by_mime = array(
				'image/png'  => 'png',
				'image/jpeg' => 'jpg',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
			);
			$mime = is_array( $image_info ) ? ( $image_info['mime'] ?? '' ) : '';
			if ( ! isset( $ext_by_mime[ $mime ] ) ) {
				return new WP_Error( 'invalid_image', __( 'imageData must be a PNG, JPEG, GIF or WebP image.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
			}

			$filename = 'cv-prod-' . time() . '-' . wp_generate_password( 6, false ) . '.' . $ext_by_mime[ $mime ];
			$upload   = wp_upload_bits( $filename, null, $image_raw );
			if ( ! empty( $upload['error'] ) ) {
				return new WP_Error( 'image_write_failed', __( 'Could not store the product image.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
			}
			$file_path = $upload['file'];

			$attachment = array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_file_name( $payload['title'] ?? $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $file_path );
			if ( $attach_id && ! is_wp_error( $attach_id ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				$product->set_image_id( $attach_id );
			}
		} elseif ( ! empty( $payload['imageId'] ) ) {
			$product->set_image_id( intval( $payload['imageId'] ) );
		}

		if ( ! $product_id ) {
			$product->set_status( 'publish' );
		}

		$saved_id = $product->save();
		if ( ! $saved_id ) {
			return new WP_Error( 'save_failed', __( 'Failed to save product.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		// Save custom metadata (barcode, product type)
		if ( isset( $payload['barcode'] ) ) {
			update_post_meta( $saved_id, self::META_BARCODE, sanitize_text_field( $payload['barcode'] ) );
		}
		if ( isset( $payload['productType'] ) ) {
			update_post_meta( $saved_id, self::META_PRODUCT_TYPE, sanitize_text_field( $payload['productType'] ) );
		}

		$saved = wc_get_product( $saved_id );
		return $saved ? self::format_product( $saved ) : array( 'id' => $saved_id );
	}

	/**
	 * Update product stock quantity atomically.
	 *
	 * @param int    $product_id Product identifier.
	 * @param int    $quantity Quantity value.
	 * @param string $action Action: 'set', 'add', 'subtract'.
	 * @return array|WP_Error Updated stock details or WP_Error.
	 */
	public static function update_product_stock( int $product_id, int $quantity, string $action = 'set' ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'wc_inactive', __( 'WooCommerce inactive.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return new WP_Error( 'stock_unmanaged', __( 'Product not found or not managing stock.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		// 'increase'/'decrease' are single SQL updates, so concurrent calls do not lose updates.
		if ( 'add' === $action ) {
			$new_val = wc_update_product_stock( $product, $quantity, 'increase' );
		} elseif ( 'subtract' === $action ) {
			$new_val = wc_update_product_stock( $product, $quantity, 'decrease' );
			if ( is_numeric( $new_val ) && $new_val < 0 ) {
				$new_val = wc_update_product_stock( $product, 0, 'set' );
			}
		} else {
			$new_val = wc_update_product_stock( $product, $quantity, 'set' );
		}

		if ( false === $new_val || null === $new_val ) {
			return new WP_Error( 'stock_update_failed', __( 'Failed to update stock.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$product = wc_get_product( $product_id );

		return array(
			'productId'    => $product_id,
			'stock'        => $new_val,
			'stockStatus'  => $product->get_stock_status(),
		);
	}

	/**
	 * Lookup barcode information for trading card products and hobby supplies.
	 *
	 * Query cascade:
	 * 1. Local WooCommerce inventory (by barcode meta or SKU)
	 * 2. Master Pokémon/TCG Catalog (by card ID or number)
	 * 3. UPCitemdb Consumer & Hobby Goods Database (sealed boxes, tins, sleeves, toploaders)
	 *
	 * @param string $barcode Barcode string.
	 * @return array|WP_Error Barcode data or WP_Error.
	 */
	public static function lookup_barcode( string $barcode ) {
		$clean_code = trim( sanitize_text_field( $barcode ) );
		if ( empty( $clean_code ) ) {
			return new WP_Error( 'empty_barcode', __( 'Barcode string required.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		// 1. Tier 1: Local WooCommerce Inventory Match
		if ( class_exists( 'WooCommerce' ) ) {
			$matching_posts = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_BARCODE,
						'value'   => $clean_code,
						'compare' => '=',
					),
					array(
						'key'     => '_sku',
						'value'   => $clean_code,
						'compare' => '=',
					),
				),
			) );

			if ( ! empty( $matching_posts ) ) {
				$p = wc_get_product( $matching_posts[0]->ID );
				if ( $p ) {
					$thumb_id = $p->get_image_id();
					return array(
						'barcode'     => $clean_code,
						'title'       => $p->get_name(),
						'brand'       => 'Local Inventory',
						'description' => $p->get_short_description() ?: $p->get_description(),
						'category'    => implode( ', ', wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) ) ),
						'imageUrl'    => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '',
						'price'       => floatval( $p->get_price() ),
						'productId'   => $p->get_id(),
						'source'      => 'local_inventory',
						'found'       => true,
					);
				}
			}
		}

		// 2. Tier 2: Card Vault Single SKU (CV-*) & SQLite Master Catalog Match
		if ( class_exists( 'Card_Vault_SKU_Generator' ) ) {
			$info = Card_Vault_SKU_Generator::identify_barcode( $clean_code );

			// Check if single card SKU in Vault Inventory
			if ( $info['type'] === 'single_sku' ) {
				global $wpdb;
				$table = $wpdb->prefix . 'xophz_vault_collector_items';
				$item = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %s OR card_payload LIKE %s LIMIT 1", $clean_code, '%' . $wpdb->esc_like( $clean_code ) . '%' ),
					ARRAY_A
				);

				if ( $item ) {
					$payload = ! empty( $item['card_payload'] ) ? json_decode( $item['card_payload'], true ) : array();
					return array(
						'barcode'     => $clean_code,
						'title'       => sprintf( '%s (%s #%s)', $item['card_name'], $item['set_name'], $item['card_number'] ),
						'brand'       => 'Card Vault Single',
						'description' => sprintf( 'Condition: %s. Consignor: %s.', $item['condition_grade'], $item['wp_user_id'] ? 'User #' . $item['wp_user_id'] : 'House' ),
						'category'    => 'Singles',
						'imageUrl'    => $payload['imageUrl'] ?? ( $payload['card']['imageUrl'] ?? '' ),
						'price'       => (float) ( $item['asking_price'] ?? $item['market_price'] ),
						'condition'   => $item['condition_grade'],
						'source'      => 'vault_single_inventory',
						'found'       => true,
					);
				}
			}
		}

		// Check SQLite Master Catalog (UPC or Catalog ID)
		if ( class_exists( 'Card_Vault_Catalog_DB' ) ) {
			$catalog_card = Card_Vault_Catalog_DB::get_card_by_barcode( $clean_code );
			if ( $catalog_card ) {
				return array(
					'barcode'     => $clean_code,
					'title'       => sprintf( '%s (%s #%s)', $catalog_card['name'], $catalog_card['group_name'], $catalog_card['raw_number'] ),
					'brand'       => $catalog_card['category_name'],
					'description' => sprintf( 'Rarity: %s. Set: %s.', $catalog_card['rarity'] ?: 'Standard', $catalog_card['group_name'] ),
					'category'    => ! empty( $catalog_card['upc'] ) ? 'Sealed Product' : 'Singles',
					'imageUrl'    => $catalog_card['image_url'],
					'price'       => (float) ( $catalog_card['market_price'] ?: $catalog_card['low_price'] ),
					'source'      => 'sqlite_master_catalog',
					'found'       => true,
				);
			}
		}

		// 3. Tier 3: UPCitemdb Consumer & Hobby Goods Database
		$upc_url      = 'https://api.upcitemdb.com/prod/trial/lookup?upc=' . urlencode( $clean_code );
		$upc_response = wp_remote_get( $upc_url, array( 'timeout' => 8, 'headers' => array( 'User-Agent' => 'CompassCardVault/1.0' ) ) );

		if ( ! is_wp_error( $upc_response ) && 200 === wp_remote_retrieve_response_code( $upc_response ) ) {
			$upc_body = json_decode( wp_remote_retrieve_body( $upc_response ), true );
			if ( isset( $upc_body['items'] ) && is_array( $upc_body['items'] ) && count( $upc_body['items'] ) > 0 ) {
				$item = $upc_body['items'][0];
				return array(
					'barcode'     => $clean_code,
					'title'       => $item['title'] ?? '',
					'brand'       => $item['brand'] ?? 'Trading Card Goods',
					'description' => $item['description'] ?? '',
					'category'    => $item['category'] ?? 'Sealed Product & Supplies',
					'imageUrl'    => ! empty( $item['images'] ) ? $item['images'][0] : '',
					'source'      => 'upc_database',
					'found'       => true,
				);
			}
		}

		return new WP_Error( 'not_found', __( 'No trading card product or hobby accessory found for barcode ' . $clean_code, 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
	}
}
