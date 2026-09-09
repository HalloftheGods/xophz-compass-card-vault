<?php
/**
 * Stripe Payments Bridge & WP Connectors Integration for Card Vault POS.
 *
 * Facilitates Stripe Checkout Session generation, mobile QR tendering,
 * and order reconciliation using credentials from WP Connectors API.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Stripe {

	/**
	 * Retrieve Stripe Secret Key from WP Connectors API with ecosystem fallbacks.
	 *
	 * @return string
	 */
	public static function get_secret_key(): string {
		if ( function_exists( 'wp_get_connectors' ) ) {
			$connectors = wp_get_connectors();
			if ( ! empty( $connectors['stripe_secret_key']['authentication']['setting_name'] ) ) {
				$key = get_option( $connectors['stripe_secret_key']['authentication']['setting_name'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
			if ( ! empty( $connectors['stripe']['authentication']['setting_name'] ) ) {
				$key = get_option( $connectors['stripe']['authentication']['setting_name'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
			if ( ! empty( $connectors['stripe']['authentication']['secret_key'] ) ) {
				$key = get_option( $connectors['stripe']['authentication']['secret_key'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
			if ( ! empty( $connectors['stripe']['setting_name'] ) ) {
				$key = get_option( $connectors['stripe']['setting_name'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
		}

		$fallback_options = array(
			'compass_stripe_secret_key',
			'xophz_compass_stripe_secret_key',
			'stripe_secret_key',
		);

		foreach ( $fallback_options as $option_name ) {
			$val = get_option( $option_name, '' );
			if ( ! empty( $val ) ) {
				return $val;
			}
		}

		if ( defined( 'STRIPE_SECRET_KEY' ) && ! empty( STRIPE_SECRET_KEY ) ) {
			return STRIPE_SECRET_KEY;
		}

		if ( ! empty( $_ENV['STRIPE_SECRET_KEY'] ) ) {
			return $_ENV['STRIPE_SECRET_KEY'];
		}

		if ( ! empty( getenv( 'STRIPE_SECRET_KEY' ) ) ) {
			return getenv( 'STRIPE_SECRET_KEY' );
		}

		return '';
	}

	/**
	 * Retrieve Stripe Publishable Key from WP Connectors or options.
	 *
	 * @return string
	 */
	public static function get_publishable_key(): string {
		if ( function_exists( 'wp_get_connectors' ) ) {
			$connectors = wp_get_connectors();
			if ( ! empty( $connectors['stripe_publishable_key']['authentication']['setting_name'] ) ) {
				$key = get_option( $connectors['stripe_publishable_key']['authentication']['setting_name'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
			if ( ! empty( $connectors['stripe']['authentication']['public_key'] ) ) {
				$key = get_option( $connectors['stripe']['authentication']['public_key'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
			if ( ! empty( $connectors['stripe']['authentication']['publishable_key'] ) ) {
				$key = get_option( $connectors['stripe']['authentication']['publishable_key'], '' );
				if ( ! empty( $key ) ) {
					return $key;
				}
			}
		}

		$fallback_options = array(
			'compass_stripe_publishable_key',
			'xophz_compass_stripe_publishable_key',
			'stripe_publishable_key',
		);

		foreach ( $fallback_options as $option_name ) {
			$val = get_option( $option_name, '' );
			if ( ! empty( $val ) ) {
				return $val;
			}
		}

		if ( defined( 'STRIPE_PUBLISHABLE_KEY' ) && ! empty( STRIPE_PUBLISHABLE_KEY ) ) {
			return STRIPE_PUBLISHABLE_KEY;
		}

		if ( ! empty( $_ENV['STRIPE_PUBLISHABLE_KEY'] ) ) {
			return $_ENV['STRIPE_PUBLISHABLE_KEY'];
		}

		if ( ! empty( getenv( 'STRIPE_PUBLISHABLE_KEY' ) ) ) {
			return getenv( 'STRIPE_PUBLISHABLE_KEY' );
		}

		return '';
	}

	/**
	 * Check if Stripe is configured on the server.
	 *
	 * @return bool
	 */
	public static function is_stripe_configured(): bool {
		$key = self::get_secret_key();
		return ! empty( $key ) && strpos( $key, 'sk_test_Mock' ) !== 0;
	}

	/**
	 * Create a WooCommerce POS order and generate a Stripe Checkout Session or QR link.
	 *
	 * @param array $payload POS checkout arguments.
	 * @return array|WP_Error Order and checkout session details or WP_Error.
	 */
	public static function create_pos_checkout( array $payload ) {
		$items          = $payload['items'] ?? array();
		$payment_method = sanitize_text_field( $payload['paymentMethod'] ?? 'stripe' );
		$discount       = isset( $payload['discount'] ) ? floatval( $payload['discount'] ) : 0.00;
		$tip_amount     = isset( $payload['tipAmount'] ) ? floatval( $payload['tipAmount'] ) : 0.00;
		$notes          = sanitize_text_field( $payload['notes'] ?? 'Card Vault POS Checkout' );
		$cashier_id     = isset( $payload['cashierId'] ) ? intval( $payload['cashierId'] ) : get_current_user_id();
		$customer_name  = sanitize_text_field( $payload['customerName'] ?? '' );
		$customer_email = sanitize_email( $payload['customerEmail'] ?? '' );
		$customer_phone = sanitize_text_field( $payload['customerPhone'] ?? '' );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return new WP_Error( 'empty_cart', __( 'Cart contains no items.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		$order = null;
		if ( class_exists( 'WC_Order' ) ) {
			$order = wc_create_order();
		}

		$order_id = $order ? $order->get_id() : time();

		$line_total = 0.00;
		$stripe_line_items = array();

		foreach ( $items as $item ) {
			$title     = sanitize_text_field( $item['title'] ?? 'Trading Card' );
			$qty       = max( 1, intval( $item['quantity'] ?? 1 ) );
			$price     = max( 0.00, floatval( $item['unitPrice'] ?? 0.00 ) );
			$vault_id  = sanitize_text_field( $item['id'] ?? '' );
			$subtotal  = $price * $qty;
			$line_total += $subtotal;

			// Add to WooCommerce order if WC is active
			if ( $order ) {
				$product_id = 0;
				if ( ! empty( $vault_id ) ) {
					$product_id = Card_Vault_WC_Sync::find_product_by_vault_id( $vault_id );
				}
				if ( ! $product_id && ! empty( $item['productId'] ) ) {
					$product_id = intval( $item['productId'] );
				}

				if ( $product_id > 0 ) {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						$order->add_product( $product, $qty, array( 'subtotal' => $subtotal, 'total' => $subtotal ) );
					}
				} else {
					$fee = new WC_Order_Item_Fee();
					$fee->set_name( $title );
					$fee->set_amount( $subtotal );
					$fee->set_total( $subtotal );
					$order->add_item( $fee );
				}
			}

			// Prepare Stripe line item
			$stripe_line_items[] = array(
				'price_data' => array(
					'currency'     => 'usd',
					'product_data' => array(
						'name'        => $title,
						'description' => ! empty( $item['subtitle'] ) ? sanitize_text_field( $item['subtitle'] ) : 'Card Vault Inventory Item',
					),
					'unit_amount'  => (int) round( $price * 100 ),
				),
				'quantity'   => $qty,
			);
		}

		$total_amount = max( 0.00, $line_total - $discount + $tip_amount );

		// Set order details
		if ( $order ) {
			if ( $discount > 0.00 ) {
				$discount_item = new WC_Order_Item_Fee();
				$discount_item->set_name( 'POS Discount' );
				$discount_item->set_amount( -$discount );
				$discount_item->set_total( -$discount );
				$order->add_item( $discount_item );
			}

			if ( $tip_amount > 0.00 ) {
				$tip_item = new WC_Order_Item_Fee();
				$tip_item->set_name( 'Staff Tip' );
				$tip_item->set_amount( $tip_amount );
				$tip_item->set_total( $tip_amount );
				$order->add_item( $tip_item );
				$order->update_meta_data( '_pos_tip_amount', $tip_amount );
			}

			$order->set_payment_method( $payment_method );
			$order->set_payment_method_title( $payment_method === 'stripe' ? 'Stripe Terminal / QR' : ucfirst( $payment_method ) );
			$order->set_created_via( 'card_vault_pos' );
			$order->update_meta_data( '_pos_cashier_id', $cashier_id );

			if ( ! empty( $customer_name ) ) {
				$parts = explode( ' ', $customer_name, 2 );
				$order->set_billing_first_name( $parts[0] );
				$order->set_billing_last_name( $parts[1] ?? '' );
			}
			if ( ! empty( $customer_email ) ) {
				$order->set_billing_email( $customer_email );
			}
			if ( ! empty( $customer_phone ) ) {
				$order->set_billing_phone( $customer_phone );
			}

			$order->calculate_totals();
			$order->save();
		}

		// Handle Stripe Payment Method
		if ( 'stripe' === $payment_method ) {
			$secret_key = self::get_secret_key();
			if ( empty( $secret_key ) ) {
				return new WP_Error(
					'missing_stripe_key',
					__( 'Stripe secret key is not configured in Settings -> Connectors UI or STRIPE_SECRET_KEY.', 'xophz-compass-card-vault' ),
					array( 'status' => 500 )
				);
			}

			$success_url = home_url( '/?xophz_card_vault_pos_complete=1&order_id=' . $order_id . '&session_id={CHECKOUT_SESSION_ID}' );
			$cancel_url  = home_url( '/?xophz_card_vault_pos_cancel=1&order_id=' . $order_id );

			$session_args = array(
				'payment_method_types' => array( 'card' ),
				'line_items'           => $stripe_line_items,
				'mode'                 => 'payment',
				'success_url'          => $success_url,
				'cancel_url'           => $cancel_url,
				'metadata'             => array(
					'order_id'   => (string) $order_id,
					'app_source' => 'card_vault_pos',
				),
			);

			$response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( $secret_key ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $session_args ),
				'timeout' => 15,
			) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'stripe_http_error', $response->get_error_message(), array( 'status' => 500 ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['error'] ) ) {
				return new WP_Error( 'stripe_api_error', $body['error']['message'], array( 'status' => 400 ) );
			}

			$checkout_url = $body['url'] ?? '';
			$session_id   = $body['id'] ?? '';
			$qr_url       = 'https://api.qrserver.com/v1/create-qr-code/?size=250&data=' . urlencode( $checkout_url );

			if ( $order ) {
				$order->update_meta_data( '_stripe_session_id', $session_id );
				$order->update_meta_data( '_stripe_checkout_url', $checkout_url );
				$order->save();
			}

			return array(
				'orderId'        => $order_id,
				'paymentMethod'  => 'stripe',
				'totalAmount'    => $total_amount,
				'checkoutUrl'    => $checkout_url,
				'sessionId'      => $session_id,
				'qrCodeUrl'      => $qr_url,
				'status'         => 'pending_payment',
				'receiptKey'     => $order ? $order->get_order_key() : '',
			);
		}

		// Handle Immediate Tenders (Cash, Store Credit, Split)
		if ( $order ) {
			$order->set_status( 'completed', 'Completed via Card Vault POS (' . ucfirst( $payment_method ) . ')' );
			$order->save();
			Card_Vault_WC_Sync::handle_order_completed( $order_id );
		}

		return array(
			'orderId'       => $order_id,
			'paymentMethod' => $payment_method,
			'totalAmount'   => $total_amount,
			'status'        => 'completed',
			'receiptUrl'    => home_url( '/?xophz_bazaar_receipt=' . $order_id . '&key=' . ( $order ? $order->get_order_key() : '' ) ),
			'receiptKey'    => $order ? $order->get_order_key() : '',
		);
	}

	/**
	 * Verify Stripe Checkout Session payment status and settle order.
	 *
	 * @param int    $order_id Order identifier.
	 * @param string $session_id Stripe checkout session ID.
	 * @return array|WP_Error Result descriptor or WP_Error.
	 */
	public static function verify_payment( int $order_id, string $session_id ) {
		$secret_key = self::get_secret_key();
		if ( empty( $secret_key ) ) {
			return new WP_Error( 'missing_stripe_key', __( 'Stripe secret key missing.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$response = wp_remote_get( 'https://api.stripe.com/v1/checkout/sessions/' . urlencode( $session_id ), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $secret_key ),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_verify_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'stripe_api_error', $body['error']['message'], array( 'status' => 400 ) );
		}

		$payment_status = $body['payment_status'] ?? 'unpaid';
		$is_paid        = ( 'paid' === $payment_status );

		if ( $is_paid && class_exists( 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && ! $order->is_paid() ) {
				$order->payment_complete( $body['payment_intent'] ?? $session_id );
				$order->add_order_note( 'Stripe POS mobile payment confirmed. Session: ' . $session_id );
				Card_Vault_WC_Sync::handle_order_completed( $order_id );
			}
		}

		return array(
			'orderId'       => $order_id,
			'sessionId'     => $session_id,
			'paymentStatus' => $payment_status,
			'isPaid'        => $is_paid,
		);
	}
}
