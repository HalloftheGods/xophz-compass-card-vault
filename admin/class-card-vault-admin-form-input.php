<?php
/**
 * Reads the Card Vault admin forms' POST data and sanitizes the plugin's settings on save.
 *
 * WordPress adds slashes to $_POST, so every reader here unslashes before it sanitizes:
 * a name like O'Brien is stored as O'Brien, the same as through the REST API.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/admin
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Admin_Form_Input {

	/**
	 * A scalar POST value as a string, or $fallback when the key is missing or not scalar.
	 *
	 * @param array  $post     Unslashed POST data.
	 * @param string $key      Field name.
	 * @param string $fallback Value for a missing or non-scalar field.
	 * @return string
	 */
	private static function text( array $post, $key, $fallback = '' ) {
		return ( isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ) ? (string) $post[ $key ] : $fallback;
	}

	/**
	 * Fields for Card_Vault_Consignments::create_consignor() from the Register Consignor form.
	 * Values are unslashed here and sanitized by create_consignor(). A blank, non-numeric or 0
	 * split rate is left out, so create_consignor() applies the store's default split rate setting.
	 *
	 * @param array $post Raw $_POST.
	 * @return array
	 */
	public static function consignor_fields( array $post ) {
		$post   = wp_unslash( $post );
		$fields = array(
			'name'          => self::text( $post, 'name' ),
			'email'         => self::text( $post, 'email' ),
			'phone'         => self::text( $post, 'phone' ),
			'wp_user_id'    => ! empty( $post['wp_user_id'] ) ? (int) $post['wp_user_id'] : 0,
			'payout_method' => self::text( $post, 'payout_method', 'Cash' ),
			'payout_handle' => self::text( $post, 'payout_handle' ),
			'notes'         => self::text( $post, 'notes' ),
		);

		$rate = $post['default_split_rate'] ?? '';
		if ( is_numeric( $rate ) && 0.0 !== (float) $rate ) {
			$fields['default_split_rate'] = (float) $rate;
		}

		return $fields;
	}

	/**
	 * Payment method and reference from the Mark Payout Paid form, unslashed and sanitized.
	 *
	 * @param array $post Raw $_POST.
	 * @return array{method: string, reference: string}
	 */
	public static function payout_payment( array $post ) {
		$post = wp_unslash( $post );
		return array(
			'method'    => sanitize_text_field( self::text( $post, 'payment_method', 'Cash' ) ),
			'reference' => sanitize_text_field( self::text( $post, 'payment_reference' ) ),
		);
	}

	/**
	 * Game categories to save from the catalog ingestion form, and the notice to show.
	 *
	 * The form posts categories_rendered=1 only when it listed at least one game. Without it
	 * (the game list could not be loaded from tcgcsv.com) categories is null and the saved
	 * selection is kept. When games were listed and none is ticked, categories is null too:
	 * the catalog reads an empty selection as Pokemon (3), so an empty selection is not saved.
	 *
	 * @param array $post Raw $_POST.
	 * @return array{categories: int[]|null, msg: string}
	 */
	public static function catalog_categories( array $post ) {
		if ( empty( $post['categories_rendered'] ) ) {
			return array( 'categories' => null, 'msg' => 'catalog_saved_categories_kept' );
		}

		$ids = ( isset( $post['categories'] ) && is_array( $post['categories'] ) )
			? array_values( array_unique( array_filter( array_map( 'intval', $post['categories'] ) ) ) )
			: array();

		if ( empty( $ids ) ) {
			return array( 'categories' => null, 'msg' => 'catalog_no_category' );
		}

		return array( 'categories' => $ids, 'msg' => 'catalog_saved' );
	}

	/**
	 * sanitize_callback for the default split rate setting: a number rounded to 2 decimals and
	 * bounded to 0-100. A non-numeric value keeps the rate saved before.
	 *
	 * @param mixed $value Submitted value.
	 * @return float|string
	 */
	public static function sanitize_split_rate( $value ) {
		if ( is_numeric( $value ) ) {
			return max( 0.0, min( 100.0, round( (float) $value, 2 ) ) );
		}
		return get_option( Card_Vault_Consignments::DEFAULT_SPLIT_RATE_OPTION, '' );
	}

	/**
	 * sanitize_callback for a checkbox setting: '1' when ticked, '0' when not. An unticked
	 * checkbox is not posted, so options.php passes null.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_checkbox( $value ) {
		return empty( $value ) ? '0' : '1';
	}
}
