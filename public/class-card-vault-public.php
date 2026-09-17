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

if ( ! defined( 'XOPHZ_COMPASS_CARD_VAULT_PATH' ) ) {
	define( 'XOPHZ_COMPASS_CARD_VAULT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'XOPHZ_COMPASS_CARD_VAULT_URL' ) ) {
	define( 'XOPHZ_COMPASS_CARD_VAULT_URL', function_exists( 'plugins_url' ) ? plugins_url( '', dirname( __DIR__ ) . '/xophz-compass-card-vault.php' ) : 'http://localhost/wp-content/plugins/xophz-compass-card-vault/' );
}
if ( ! defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ) {
	define( 'XOPHZ_COMPASS_CARD_VAULT_VERSION', '26.9.17' );
}

class Card_Vault_Public {

	/**
	 * Dedicated dev port for Card Vault Vite dev server.
	 */
	const DEV_PORT = 8092;

	/**
	 * Consolidated Dev Proxy instance.
	 *
	 * @var Xophz_Compass_Dev_Proxy|null
	 */
	protected $dev_proxy = null;

	/**
	 * Initialize the proxy.
	 */
	public function __construct() {
		if ( class_exists( 'Xophz_Compass_Dev_Proxy' ) ) {
			$this->dev_proxy = new Xophz_Compass_Dev_Proxy( array(
				'slug'                 => 'card-vault',
				'default_slug'         => 'card-vault',
				'dev_port'             => self::DEV_PORT,
				'query_var'            => 'xophz_compass_card_vault',
				'plugin_path'          => XOPHZ_COMPASS_CARD_VAULT_PATH,
				'plugin_url'           => XOPHZ_COMPASS_CARD_VAULT_URL,
				'version'              => XOPHZ_COMPASS_CARD_VAULT_VERSION,
				'candidate_dist_paths' => array(
					XOPHZ_COMPASS_CARD_VAULT_PATH . 'public/dist/index.html',
					dirname( XOPHZ_COMPASS_CARD_VAULT_PATH, 3 ) . '/apps/my-card-vault/dist/index.html',
					ABSPATH . 'apps/my-card-vault/dist/index.html',
				),
			) );

			add_filter( 'xophz_compass_dev_proxy_card-vault_api_settings', array( $this, 'filter_api_settings' ), 10, 2 );
			add_filter( 'xophz_compass_dev_proxy_settings', array( $this, 'filter_api_settings' ), 10, 2 );
			add_filter( 'xophz_compass_dev_proxy_card-vault_html', array( $this, 'filter_html_output' ), 10, 2 );
			add_filter( 'xophz_compass_dev_proxy_html', array( $this, 'filter_html_output' ), 10, 2 );
		}
	}

	/**
	 * Delegate rewrite registration for activator backward compatibility.
	 */
	public function register_rewrites(): void {
		if ( $this->dev_proxy ) {
			$this->dev_proxy->register_rewrites();
		}
	}

	/**
	 * Get the internal dev proxy instance.
	 *
	 * @return Xophz_Compass_Dev_Proxy|null
	 */
	public function get_dev_proxy() {
		return $this->dev_proxy;
	}

	/**
	 * Injects dynamic WordPress site title and application metadata into served HTML.
	 *
	 * @param string $html Output HTML string.
	 * @param string $slug Plugin slug identifier.
	 * @return string
	 */
	public function filter_html_output( string $html, string $slug = '' ): string {
		if ( ! empty( $slug ) && 'card-vault' !== $slug ) {
			return $html;
		}

		$raw_title  = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
		$site_title = function_exists( 'wp_specialchars_decode' )
			? wp_specialchars_decode( $raw_title, ENT_QUOTES )
			: htmlspecialchars_decode( (string) $raw_title, ENT_QUOTES );
		if ( empty( $site_title ) ) {
			$site_title = 'My Card Vault';
		}

		$escaped_title = function_exists( 'esc_html' ) ? esc_html( $site_title ) : htmlspecialchars( $site_title, ENT_QUOTES, 'UTF-8' );
		$attr_title    = function_exists( 'esc_attr' ) ? esc_attr( $site_title ) : htmlspecialchars( $site_title, ENT_QUOTES, 'UTF-8' );

		// 1. Replace <title> tag with dynamic site title
		if ( preg_match( '#<title>.*?</title>#is', $html ) ) {
			$html = preg_replace( '#<title>.*?</title>#is', '<title>' . $escaped_title . '</title>', $html, 1 );
		}

		// 2. Dynamically replace application-name meta tag
		if ( preg_match( '#<meta\s+name=["\']application-name["\']\s+content=["\'][^"\']*["\']#is', $html ) ) {
			$html = preg_replace(
				'#<meta\s+name=["\']application-name["\']\s+content=["\'][^"\']*["\']#is',
				'<meta name="application-name" content="' . $attr_title . '"',
				$html,
				1
			);
		}

		// 3. Dynamically replace apple-mobile-web-app-title meta tag
		if ( preg_match( '#<meta\s+name=["\']apple-mobile-web-app-title["\']\s+content=["\'][^"\']*["\']#is', $html ) ) {
			$html = preg_replace(
				'#<meta\s+name=["\']apple-mobile-web-app-title["\']\s+content=["\'][^"\']*["\']#is',
				'<meta name="apple-mobile-web-app-title" content="' . $attr_title . '"',
				$html,
				1
			);
		}

		return $html;
	}

	/**
	 * Injects Card Vault specific role and consignor metadata into window.wpApiSettings.
	 *
	 * @param array  $payload Current API settings.
	 * @param string $slug    Plugin slug.
	 * @return array
	 */
	public function filter_api_settings( array $payload, string $slug ): array {
		if ( 'card-vault' !== $slug ) {
			return $payload;
		}

		$raw_title  = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
		$site_title = function_exists( 'wp_specialchars_decode' )
			? wp_specialchars_decode( $raw_title, ENT_QUOTES )
			: htmlspecialchars_decode( (string) $raw_title, ENT_QUOTES );

		$payload['siteTitle']     = ! empty( $site_title ) ? $site_title : 'My Card Vault';
		$payload['version']       = defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ? XOPHZ_COMPASS_CARD_VAULT_VERSION : '26.9.12-1209';
		$payload['versionString'] = defined( 'XOPHZ_COMPASS_CARD_VAULT_VERSION' ) ? XOPHZ_COMPASS_CARD_VAULT_VERSION : '26.9.12-1209';

		$user_id = ! empty( $payload['userId'] ) ? (int) $payload['userId'] : get_current_user_id();
		$u = wp_get_current_user();
		$roles = $u && $u->ID ? (array) $u->roles : array();
		$is_dealer = current_user_can( 'manage_options' ) || current_user_can( 'manage_card_vault' ) || in_array( 'shop_manager', $roles, true );
		$consignor = class_exists( 'Card_Vault_Consignments' ) && $user_id ? Card_Vault_Consignments::get_consignor_by_user_id( $user_id ) : null;

		$role = 'guest';
		if ( $user_id > 0 ) {
			if ( $is_dealer ) {
				$role = 'dealer';
			} elseif ( in_array( 'card_vault_consignor', $roles, true ) ) {
				$role = 'consignor';
			} else {
				$role = 'collector';
			}
		}

		if ( ! isset( $payload['currentUser'] ) || ! is_array( $payload['currentUser'] ) ) {
			$payload['currentUser'] = array();
		}

		$payload['currentUser']['role']        = $role;
		$payload['currentUser']['consignorId'] = $consignor ? $consignor['consignor_id'] : null;
		$payload['currentUser']['userLogin']   = $u && $u->ID ? $u->user_login : '';
		$payload['currentUser']['displayName'] = $u && $u->ID ? $u->display_name : '';

		if ( class_exists( 'Card_Vault_Community' ) ) {
			$payload['communitySettings'] = Card_Vault_Community::get_settings();
		}

		return $payload;
	}
}
