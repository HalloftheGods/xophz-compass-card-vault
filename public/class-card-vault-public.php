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
