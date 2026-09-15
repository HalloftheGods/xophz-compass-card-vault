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
	public static function generate_content( $parts, $system_instruction = '', $model = 'gemini-3.6-flash' ) {
		if ( empty( $model ) || 'gemini-2.5-flash' === $model || 'gemini-2.5-pro' === $model ) {
			$configured = get_option( 'card_vault_gemini_model', '' );
			if ( ! empty( $configured ) ) {
				$model = $configured;
			} elseif ( defined( 'GEMINI_MODEL' ) && ! empty( GEMINI_MODEL ) ) {
				$model = GEMINI_MODEL;
			} else {
				$model = 'gemini-3.6-flash';
			}
		}
		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'missing_gemini_key',
				__( 'Gemini API key is not configured in WP Connectors or environment.', 'xophz-compass-card-vault' ),
				array( 'status' => 500 )
			);
		}

		// Prepare resilient model failover list (primary model + fallback models)
		$models_to_try = array( $model );
		if ( 'gemini-3.5-flash' !== $model ) {
			$models_to_try[] = 'gemini-3.5-flash';
		}
		if ( 'gemini-3.6-flash' !== $model && ! in_array( 'gemini-3.6-flash', $models_to_try, true ) ) {
			$models_to_try[] = 'gemini-3.6-flash';
		}

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

		$last_error = null;

		foreach ( $models_to_try as $attempt_model ) {
			$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$attempt_model}:generateContent?key={$api_key}";

			$response = wp_remote_post( $endpoint, array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 45,
			) );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			// If Google returns 503 (demand spike), 429 (rate limit), or 404 (model retired), fail over to next model
			if ( in_array( $code, array( 503, 429, 404 ), true ) ) {
				$last_error = new WP_Error(
					'gemini_api_error',
					sprintf( 'Gemini API model %s returned status %d: %s', $attempt_model, $code, $body ),
					array( 'status' => $code )
				);
				continue;
			}

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

		return $last_error ? $last_error : new WP_Error( 'gemini_api_failed', __( 'All Gemini models in fallback chain failed.', 'xophz-compass-card-vault' ) );
	}
}
