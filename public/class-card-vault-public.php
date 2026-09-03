<?php
/**
 * Public frontend router, dev reverse proxy, and production dist loader.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/public
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Public {

	/**
	 * Dedicated dev port for Card Vault Vite dev server.
	 */
	const DEV_PORT = 8092;

	/**
	 * Register hooks for rewrite rules and template interception.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	/**
	 * Register custom query vars for Card Vault routing.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'xophz_compass_card_vault';
		return $vars;
	}

	/**
	 * Register rewrite rules for the Card Vault frontend single-page application.
	 */
	public function register_rewrites() {
		$slug = get_option( 'xophz_compass_card_vault_custom_slug', 'card-vault' );
		if ( ! empty( $slug ) ) {
			add_rewrite_rule(
				'^' . preg_quote( $slug, '/' ) . '/?$',
				'index.php?xophz_compass_card_vault=1',
				'top'
			);
			add_rewrite_rule(
				'^' . preg_quote( $slug, '/' ) . '/(.*)?$',
				'index.php?xophz_compass_card_vault=1',
				'top'
			);
		}
	}

	/**
	 * Check whether current environment is running in development mode.
	 *
	 * @return bool
	 */
	private function is_dev_mode() {
		return ( defined( 'WP_ENV' ) && 'development' === WP_ENV ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Intercept front-end requests targeting the Card Vault deployment slug.
	 */
	public function template_redirect() {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( strpos( $request_uri, '/wp-admin' ) === 0 || strpos( $request_uri, '/wp-login.php' ) === 0 ) {
			return;
		}

		if ( get_query_var( 'xophz_compass_card_vault' ) ) {
			status_header( 200 );
			global $wp_query;
			if ( $wp_query ) {
				$wp_query->is_404 = false;
			}

			$is_dev    = $this->is_dev_mode();
			$vite_port = self::DEV_PORT;

			if ( isset( $_SERVER['HTTP_HOST'] ) ) {
				$host_parts = explode( ':', $_SERVER['HTTP_HOST'] );
				$wp_host    = $host_parts[0];
			} else {
				$wp_host = wp_parse_url( home_url(), PHP_URL_HOST );
			}
			$vite_url = '//' . $wp_host . ':' . $vite_port;

			// Dev Server Proxy Mode
			if ( $is_dev ) {
				$dev_hosts = array( 'compass', '127.0.0.1', 'localhost' );
				$dev_html  = false;

				foreach ( $dev_hosts as $host ) {
					$context  = stream_context_create( array(
						'http' => array(
							'timeout'          => 1,
							'suppress_errors'  => true,
						),
					) );
					$dev_html = @file_get_contents( "http://{$host}:{$vite_port}/", false, $context );
					if ( $dev_html ) {
						break;
					}
				}

				if ( $dev_html ) {
					// Rewrite relative asset imports to Vite dev server
					$dev_html = str_replace( 'src="/', 'src="' . $vite_url . '/', $dev_html );
					$dev_html = str_replace( 'href="/', 'href="' . $vite_url . '/', $dev_html );
					$dev_html = str_replace( 'import("/', 'import("' . $vite_url . '/', $dev_html );
					$dev_html = str_replace( 'from "/', 'from="' . $vite_url . '/', $dev_html );
					$dev_html = str_replace( "from '/", "from '" . $vite_url . '/', $dev_html );

					// Inject Vite client for HMR
					if ( strpos( $dev_html, '/@vite/client' ) === false ) {
						$vite_client = '<script type="module" src="' . esc_url( $vite_url ) . '/@vite/client"></script>';
						$dev_html    = str_replace( '</head>', $vite_client . "\n</head>", $dev_html );
					}

					// Inject window.wpApiSettings
					$wp_settings = $this->build_wp_api_settings_script();
					$dev_html    = str_replace( '</head>', $wp_settings . "\n</head>", $dev_html );

					header( 'Content-Type: text/html; charset=UTF-8' );
					echo $dev_html;
					exit;
				}
			}

			// Production Dist Mode
			$candidate_paths = array(
				XOPHZ_COMPASS_CARD_VAULT_PATH . 'public/dist/index.html',
				dirname( XOPHZ_COMPASS_CARD_VAULT_PATH, 3 ) . '/apps/my-card-vault/dist/index.html',
				ABSPATH . 'apps/my-card-vault/dist/index.html',
			);

			$index_path = false;
			$dist_url   = XOPHZ_COMPASS_CARD_VAULT_URL . 'public/dist/';

			foreach ( $candidate_paths as $path ) {
				if ( file_exists( $path ) ) {
					$index_path = $path;
					break;
				}
			}

			if ( $index_path ) {
				$content = file_get_contents( $index_path );

				// Rewrite absolute asset paths to plugin public/dist/
				$content = str_replace( '"/assets/', '"' . $dist_url . 'assets/', $content );
				$content = str_replace( "'/assets/", "'" . $dist_url . 'assets/', $content );
				$content = str_replace( '"/vite.svg"', '"' . $dist_url . 'vite.svg"', $content );

				// Inject window.wpApiSettings
				$wp_settings = $this->build_wp_api_settings_script();
				$content     = str_replace( '</head>', $wp_settings . "\n</head>", $content );

				header( 'Content-Type: text/html; charset=UTF-8' );
				echo $content;
				exit;
			}

			// Fallback placeholder if not built yet
			header( 'Content-Type: text/html; charset=UTF-8' );
			echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Card Vault</title>';
			echo '<style>body{margin:0;padding:40px;background:#0d131f;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;box-sizing:border-box;text-align:center;}h1{color:#62c9ff;margin-bottom:8px;}p{color:#94a3b8;max-width:540px;line-height:1.6;}code{background:#1e293b;padding:3px 8px;border-radius:4px;color:#62c9ff;}</style></head><body>';
			echo '<h1>My Card Vault</h1>';
			echo '<p>Development server on port <code>' . esc_html( self::DEV_PORT ) . '</code> is not currently responding, and production assets have not been compiled yet.</p>';
			echo '<p>To run in development mode, start the dev server with <code>pnpm run dev:card-vault</code>.</p>';
			echo '<p>To compile production assets, run <code>pnpm run build:card-vault</code>.</p>';
			echo '</body></html>';
			exit;
		}
	}

	/**
	 * Build window.wpApiSettings script element.
	 *
	 * @return string
	 */
	private function build_wp_api_settings_script() {
		$nonce     = wp_create_nonce( 'wp_rest' );
		$user_id   = get_current_user_id();
		$user_data = null;

		if ( $user_id > 0 ) {
			$u         = wp_get_current_user();
			$roles     = (array) $u->roles;
			$is_dealer = in_array( 'administrator', $roles, true ) || in_array( 'shop_manager', $roles, true ) || current_user_can( 'manage_card_vault' );
			$consignor = Card_Vault_Consignments::get_consignor_by_user_id( $user_id );

			$user_data = array(
				'id'           => 'wp-' . $user_id,
				'username'     => $u->user_login,
				'email'        => $u->user_email,
				'fullName'     => $u->display_name ?: $u->user_login,
				'avatarUrl'    => get_avatar_url( $user_id ) ?: '',
				'role'         => $is_dealer ? 'dealer' : ( in_array( 'card_vault_consignor', $roles, true ) ? 'consignor' : 'user' ),
				'consignorId'  => $consignor ? $consignor['consignor_id'] : null,
				'registeredAt' => strtotime( $u->user_registered ) * 1000,
			);
		}

		$settings = array(
			'root'        => esc_url_raw( rest_url() ),
			'nonce'       => $nonce,
			'pluginUrl'   => esc_url_raw( XOPHZ_COMPASS_CARD_VAULT_URL ),
			'version'     => XOPHZ_COMPASS_CARD_VAULT_VERSION,
			'userId'      => $user_id,
			'currentUser' => $user_data,
		);

		return '<script>window.wpApiSettings = ' . wp_json_encode( $settings ) . ';</script>';
	}
}
