<?php
/**
 * Single Card SKU and Code 128 Barcode Generator.
 *
 * Generates deterministic, scanner-friendly SKUs for physical singles
 * and renders pure SVG Code 128 barcodes for 2.25" × 1.25" thermal labels.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_SKU_Generator {

	/**
	 * Generate a deterministic SKU for a physical inventory item.
	 *
	 * @param array $item Inventory item details.
	 * @return string Standardized SKU string (e.g. "CV-PKM-MEW-199-NM-4F2A").
	 */
	public static function generate_sku( array $item ): string {
		$card       = $item['card'] ?? array();
		$raw_game   = function_exists( 'sanitize_key' ) ? sanitize_key( $card['game'] ?? 'PKM' ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) ( $card['game'] ?? 'PKM' ) ) );
		$game_code  = strtoupper( substr( $raw_game, 0, 3 ) );
		$set_code   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $card['setCode'] ?? $card['set_code'] ?? 'SET' ) ) );
		$number_raw = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $card['number'] ?? $card['card_number'] ?? '0' ) );
		$raw_cond   = (string) ( $item['condition'] ?? $item['condition_grade'] ?? 'NM' );
		$condition  = strtoupper( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw_cond ) : trim( strip_tags( $raw_cond ) ) );

		// Short item unique identifier (4 hex chars)
		$item_id = (string) ( $item['id'] ?? $item['item_id'] ?? '' );
		$short_hash = substr( hash( 'crc32b', $item_id . ( $item['wp_user_id'] ?? '' ) ), 0, 4 );

		// Check if graded
		$is_graded   = ! empty( $item['is_graded'] ) || ! empty( $item['grade_label'] );
		$raw_grade   = (string) ( $item['grade_label'] ?? '' );
		$grade_label = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw_grade ) : trim( strip_tags( $raw_grade ) );
		$cert_number = preg_replace( '/\D/', '', (string) ( $item['cert_number'] ?? '' ) );

		if ( $is_graded && ! empty( $grade_label ) ) {
			$clean_grade = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $grade_label ) );
			if ( ! empty( $cert_number ) ) {
				$short_cert = substr( $cert_number, -6 );
				return "CV-{$clean_grade}-{$set_code}-{$number_raw}-{$short_cert}";
			}
			return "CV-{$clean_grade}-{$set_code}-{$number_raw}-{$short_hash}";
		}

		$is_foil = ! empty( $item['is_foil'] ) ? '-F' : '';
		return "CV-{$game_code}-{$set_code}-{$number_raw}-{$condition}{$is_foil}-{$short_hash}";
	}

	/**
	 * Inspect an incoming barcode string to identify its type.
	 *
	 * @param string $barcode Scanned barcode string.
	 * @return array{type: 'single_sku'|'upc'|'cert'|'unknown', clean_value: string}
	 */
	public static function identify_barcode( string $barcode ): array {
		$trimmed = trim( $barcode );

		// 1. Single Card Vault SKU
		if ( strpos( $trimmed, 'CV-' ) === 0 || strpos( $trimmed, 'cv-' ) === 0 ) {
			return array(
				'type'        => 'single_sku',
				'clean_value' => strtoupper( $trimmed ),
			);
		}

		// 2. Manufacturer UPC / EAN (8, 12, 13, or 14 digits)
		if ( preg_match( '/^\d{8,14}$/', $trimmed ) ) {
			return array(
				'type'        => 'upc',
				'clean_value' => $trimmed,
			);
		}

		// 3. PSA / BGS / CGC Certification Number (6 to 10 digits)
		if ( preg_match( '/^[0-9]{7,10}$/', $trimmed ) ) {
			return array(
				'type'        => 'cert',
				'clean_value' => $trimmed,
			);
		}

		return array(
			'type'        => 'unknown',
			'clean_value' => $trimmed,
		);
	}

	/**
	 * Render a minimal Code 128-B barcode as inline SVG.
	 *
	 * Uses standard Code 128 subset B table for alphanumeric strings.
	 *
	 * @param string $text Text to encode.
	 * @param int    $height Barcode height in pixels (default: 40).
	 * @param float  $bar_width Width of a single module in pixels (default: 1.5).
	 * @return string SVG element markup.
	 */
	public static function render_code128_svg( string $text, int $height = 40, float $bar_width = 1.4 ): string {
		$patterns = self::get_code128_patterns();
		$chars    = str_split( $text );
		$values   = array( 104 ); // Start Code B

		$checksum = 104;
		$weight   = 1;

		foreach ( $chars as $char ) {
			$ascii = ord( $char );
			$val   = $ascii - 32;
			if ( $val < 0 || $val > 95 ) {
				$val = 0; // Fallback to space
			}
			$values[]  = $val;
			$checksum += ( $val * $weight );
			$weight++;
		}

		$checksum_val = $checksum % 103;
		$values[]     = $checksum_val;
		$values[]     = 106; // Stop character

		// Convert patterns into bar sequence
		$bar_pattern = '';
		foreach ( $values as $idx => $val ) {
			$pattern = $patterns[ $val ] ?? '211214';
			$bar_pattern .= $pattern;
		}
		$bar_pattern .= '2'; // Final termination bar for stop char

		// Build SVG rects
		$total_modules = 0;
		$rects = '';
		$current_x = 0.0;
		$is_bar = true;

		$pattern_len = strlen( $bar_pattern );
		for ( $i = 0; $i < $pattern_len; $i++ ) {
			$width_units = (int) $bar_pattern[ $i ];
			$pixel_w = $width_units * $bar_width;

			if ( $is_bar ) {
				$rects .= sprintf(
					'<rect x="%.2f" y="0" width="%.2f" height="%d" fill="#000000" />',
					$current_x,
					$pixel_w,
					$height
				);
			}

			$current_x += $pixel_w;
			$total_modules += $width_units;
			$is_bar = ! $is_bar;
		}

		$total_width = ceil( $current_x );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" style="display:block;margin:0 auto;">%s</svg>',
			$total_width,
			$height,
			$total_width,
			$height,
			$rects
		);
	}

	/**
	 * Code 128 character pattern definitions (widths of alternating bars & spaces).
	 *
	 * @return array<int, string>
	 */
	private static function get_code128_patterns(): array {
		return array(
			0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
			5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
			10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
			15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
			20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
			25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
			30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
			35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
			40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
			45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
			50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
			55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
			60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
			65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
			70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
			75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
			80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
			85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
			90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
			95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
			100 => '114131', 101 => '311141', 102 => '411131', 103 => '211412', 104 => '211214', // Start B
			105 => '211232', 106 => '2331112', // Stop
		);
	}
}
