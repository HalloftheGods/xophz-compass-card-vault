<?php
/**
 * WP Connectors integration and Google Gemini API client for optical card scanning and grading.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Gemini {

	/**
	 * Retrieve the official Gemini API key via WP Connectors API with ecosystem fallbacks.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( function_exists( 'wp_get_connectors' ) ) {
			$connectors = wp_get_connectors();
			if ( ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
				$api_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
				if ( ! empty( $api_key ) ) {
					return $api_key;
				}
			}
			if ( ! empty( $connectors['google_gemini_api_key']['authentication']['setting_name'] ) ) {
				$api_key = get_option( $connectors['google_gemini_api_key']['authentication']['setting_name'], '' );
				if ( ! empty( $api_key ) ) {
					return $api_key;
				}
			}
		}

		$fallback_keys = array(
			'connectors_ai_google_api_key',
			'ai_google_api_key',
			'compass_gemini_api_key',
			'xophz_gemini_api_key',
		);
		foreach ( $fallback_keys as $k ) {
			$val = get_option( $k, '' );
			if ( ! empty( $val ) ) {
				return $val;
			}
		}

		if ( defined( 'GEMINI_API_KEY' ) && ! empty( GEMINI_API_KEY ) ) {
			return GEMINI_API_KEY;
		}
		if ( ! empty( $_ENV['GEMINI_API_KEY'] ) ) {
			return $_ENV['GEMINI_API_KEY'];
		}
		if ( ! empty( getenv( 'GEMINI_API_KEY' ) ) ) {
			return getenv( 'GEMINI_API_KEY' );
		}

		return '';
	}

	/**
	 * Execute a multimodal request to the Gemini API.
	 *
	 * @param array  $parts Multimodal parts (text, inlineData with base64).
	 * @param string $system_instruction Optional system prompt.
	 * @param string $model Model identifier.
	 * @return array|WP_Error Parsed response or WP_Error.
	 */
	public static function generate_content( $parts, $system_instruction = '', $model = 'gemini-2.5-flash' ) {
		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'missing_gemini_key',
				__( 'Gemini API key is not configured in WP Connectors or environment.', 'xophz-compass-card-vault' ),
				array( 'status' => 500 )
			);
		}

		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

		$payload = array(
			'contents' => array(
				array(
					'parts' => $parts,
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'temperature'      => 0.2,
			),
		);

		if ( ! empty( $system_instruction ) ) {
			$payload['system_instruction'] = array(
				'parts' => array(
					array( 'text' => $system_instruction ),
				),
			);
		}

		$response = wp_remote_post( $endpoint, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'gemini_api_error',
				sprintf( 'Gemini API returned error code %d: %s', $code, $body ),
				array( 'status' => $code )
			);
		}

		$data = json_decode( $body, true );
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

		if ( empty( $text ) ) {
			return new WP_Error( 'empty_gemini_response', __( 'Gemini returned an empty response.', 'xophz-compass-card-vault' ) );
		}

		$parsed = json_decode( $text, true );
		if ( null === $parsed ) {
			return array( 'raw_text' => $text );
		}

		return $parsed;
	}
}
