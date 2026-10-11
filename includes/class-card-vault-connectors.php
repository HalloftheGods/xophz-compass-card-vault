<?php
/**
 * Card Vault credentials, resolved through the WordPress core Connectors API.
 *
 * Every secret this plugin uses (Gemini, Stripe, Cloudflare Turnstile) is read here and nowhere
 * else. Core exposes no public function that returns a key's value, so get_key() follows the same
 * precedence as core's _wp_connectors_get_api_key_source(): environment variable, then PHP
 * constant, then the database option. Every name comes from the connector's own `authentication`
 * definition, so this plugin never hard-codes an option, constant or environment variable name.
 *
 * Values are for server-side use only. Never echo one, log one, return one from a REST route or
 * put one in a URL.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Connectors {

	/** Core AI provider connector for Google (Gemini). */
	const GOOGLE = 'google';

	/** Registered by the Compass core plugin. */
	const STRIPE_SECRET_KEY = 'stripe_secret_key';

	const STRIPE_PUBLISHABLE_KEY = 'stripe_publishable_key';

	const TURNSTILE_SITE_KEY = 'cloudflare_turnstile_site_key';

	const TURNSTILE_SECRET_KEY = 'cloudflare_turnstile_secret_key';

	/**
	 * Hook connector registration. Priority 20 runs after the Compass core plugin (priority 10),
	 * so a connector core already registers keeps core's definition.
	 */
	public static function init(): void {
		add_action( 'wp_connectors_init', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Register the connectors that no other plugin provides.
	 *
	 * @param object $registry WP_Connector_Registry.
	 */
	public static function register( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		self::register_once(
			$registry,
			self::STRIPE_PUBLISHABLE_KEY,
			'Stripe Publishable Key',
			'Stripe publishable key (pk_live_ or pk_test_) used by Card Vault POS checkout. A public identifier, not a secret.',
			'compass_stripe_publishable_key',
			'STRIPE_PUBLISHABLE_KEY',
			'https://dashboard.stripe.com/apikeys'
		);
		self::register_once(
			$registry,
			self::TURNSTILE_SITE_KEY,
			'Cloudflare Turnstile Site Key',
			'Public site key for the Cloudflare Turnstile widget on the Card Vault login and registration forms.',
			'compass_cloudflare_turnstile_site_key',
			'CLOUDFLARE_TURNSTILE_SITE_KEY',
			'https://dash.cloudflare.com/?to=/:account/turnstile'
		);
		self::register_once(
			$registry,
			self::TURNSTILE_SECRET_KEY,
			'Cloudflare Turnstile Secret Key',
			'Secret key used server-side to verify Turnstile tokens on the Card Vault login and registration forms.',
			'compass_cloudflare_turnstile_secret_key',
			'CLOUDFLARE_TURNSTILE_SECRET_KEY',
			'https://dash.cloudflare.com/?to=/:account/turnstile'
		);
	}

	/**
	 * The credential for a registered connector, or '' when it is unregistered or unset.
	 *
	 * @param string $connector_id Connector id, e.g. 'google' or 'stripe_secret_key'.
	 * @return string
	 */
	public static function get_key( string $connector_id ): string {
		$is_available = function_exists( 'wp_get_connector' ) && function_exists( 'wp_is_connector_registered' );
		if ( ! $is_available || ! wp_is_connector_registered( $connector_id ) ) {
			return '';
		}

		$connector = wp_get_connector( $connector_id );
		$auth      = is_array( $connector ) ? ( $connector['authentication'] ?? array() ) : array();

		$from_env = self::from_env( (string) ( $auth['env_var_name'] ?? '' ) );
		if ( '' !== $from_env ) {
			return $from_env;
		}

		$from_constant = self::from_constant( (string) ( $auth['constant_name'] ?? '' ) );
		if ( '' !== $from_constant ) {
			return $from_constant;
		}

		return self::from_option( (string) ( $auth['setting_name'] ?? '' ) );
	}

	private static function register_once( $registry, string $id, string $name, string $description, string $setting_name, string $env_name, string $credentials_url ): void {
		if ( function_exists( 'wp_is_connector_registered' ) && wp_is_connector_registered( $id ) ) {
			return;
		}

		$registry->register(
			$id,
			array(
				'name'           => $name,
				'description'    => $description,
				'type'           => 'api_key',
				'authentication' => array(
					'method'          => 'api_key',
					'setting_name'    => $setting_name,
					'constant_name'   => $env_name,
					'env_var_name'    => $env_name,
					'credentials_url' => $credentials_url,
				),
			)
		);
	}

	private static function from_env( string $env_var_name ): string {
		return '' === $env_var_name ? '' : self::as_string( getenv( $env_var_name ) );
	}

	private static function from_constant( string $constant_name ): string {
		$is_defined = '' !== $constant_name && defined( $constant_name );
		return $is_defined ? self::as_string( constant( $constant_name ) ) : '';
	}

	private static function from_option( string $setting_name ): string {
		return '' === $setting_name ? '' : self::as_string( get_option( $setting_name, '' ) );
	}

	private static function as_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
