<?php
/**
 * Card Number Normalizer and Search Query Sanitizer for TCG Cards.
 *
 * Decomposes complex card numbers (e.g. 004/102, TG01/TG30, OP05-001, 199/165)
 * into deterministic components, precomputes full-text search variants,
 * and sanitizes user search input to prevent FTS5 syntax errors.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Number_Normalizer {

	/**
	 * Decompose a raw card number string into structured components.
	 *
	 * @param string $raw_number Raw number string from upstream (e.g. "004/102", "TG01/TG30").
	 * @return array{
	 *     raw_number: string,
	 *     clean_number: string,
	 *     numeric_number: ?int,
	 *     number_prefix: ?string,
	 *     total_set_number: ?int,
	 *     number_variants: string
	 * }
	 */
	public static function normalize( string $raw_number ): array {
		$raw = trim( $raw_number );
		if ( empty( $raw ) ) {
			return array(
				'raw_number'       => '',
				'clean_number'     => '',
				'numeric_number'   => null,
				'number_prefix'    => null,
				'total_set_number' => null,
				'number_variants'  => '',
			);
		}

		$clean_number     = $raw;
		$total_set_number = null;
		$number_prefix    = null;
		$numeric_number   = null;

		// 1. Split on slash for set totals (e.g. "199/165", "TG01/TG30")
		if ( strpos( $raw, '/' ) !== false ) {
			$parts = explode( '/', $raw, 2 );
			$clean_number = trim( $parts[0] );
			$denom = preg_replace( '/\D/', '', trim( $parts[1] ) );
			if ( is_numeric( $denom ) ) {
				$total_set_number = (int) $denom;
			}
		}

		// 2. Extract prefix and pure numeric value (e.g. "TG01", "GG35", "SWSH001", "OP05-001", "004")
		if ( preg_match( '/^([A-Za-z]+[-_]?|[A-Za-z]+\d+[-_])(\d+.*)$/', $clean_number, $matches ) ) {
			$number_prefix = rtrim( $matches[1], '-_' );
			$num_part      = preg_replace( '/\D/', '', $matches[2] );
			if ( $num_part !== '' ) {
				$numeric_number = (int) $num_part;
			}
		} elseif ( preg_match( '/^\d+/', $clean_number, $matches ) ) {
			$numeric_number = (int) $matches[0];
		}

		// 3. Precompute space-separated search variants
		$variants = self::generate_variants( $raw, $clean_number, $numeric_number, $number_prefix, $total_set_number );

		return array(
			'raw_number'       => $raw,
			'clean_number'     => $clean_number,
			'numeric_number'   => $numeric_number,
			'number_prefix'    => $number_prefix,
			'total_set_number' => $total_set_number,
			'number_variants'  => $variants,
		);
	}

	/**
	 * Generate distinct tokens for full-text search indexing.
	 *
	 * @param string      $raw
	 * @param string      $clean
	 * @param int|null    $numeric
	 * @param string|null $prefix
	 * @param int|null    $total
	 * @return string Space-delimited search tokens.
	 */
	public static function generate_variants( string $raw, string $clean, ?int $numeric, ?string $prefix, ?int $total ): string {
		$tokens = array();

		// Add raw cleaned strings
		$tokens[] = $raw;
		$tokens[] = $clean;

		// Normalized slash format: "4_102" and "004_102"
		if ( strpos( $raw, '/' ) !== false ) {
			$tokens[] = str_replace( '/', '_', $raw );
		}

		// Add stripped numeric values
		if ( $numeric !== null ) {
			$tokens[] = (string) $numeric;
			$tokens[] = '#' . $numeric;
			$tokens[] = str_pad( (string) $numeric, 3, '0', STR_PAD_LEFT ); // "004"
			$tokens[] = '#' . str_pad( (string) $numeric, 3, '0', STR_PAD_LEFT ); // "#004"

			if ( $total !== null ) {
				$tokens[] = $numeric . '_' . $total;
				$tokens[] = str_pad( (string) $numeric, 3, '0', STR_PAD_LEFT ) . '_' . $total;
			}
		}

		// Add prefix combinations (e.g. TG01, TG1, TG-01, TG 1)
		if ( ! empty( $prefix ) && $numeric !== null ) {
			$clean_prefix = strtoupper( str_replace( array( '-', '_' ), '', $prefix ) );
			$tokens[] = $clean_prefix . $numeric;
			$tokens[] = $clean_prefix . str_pad( (string) $numeric, 2, '0', STR_PAD_LEFT );
			$tokens[] = $clean_prefix . '-' . $numeric;
			$tokens[] = $clean_prefix . '_' . $numeric;
			$tokens[] = $clean_prefix . ' ' . $numeric;
		}

		// Add denominator if present
		if ( $total !== null ) {
			$tokens[] = (string) $total;
		}

		$unique_tokens = array_unique( array_filter( array_map( 'trim', $tokens ) ) );
		return implode( ' ', $unique_tokens );
	}

	/**
	 * Sanitize user query string for safe and intelligent SQLite FTS5 execution.
	 *
	 * Converts slash patterns like "4/102" into "4_102 4 102" and strips unsafe
	 * SQLite FTS operators that cause syntax errors (*, +, ", :, etc.).
	 *
	 * @param string $query User search string.
	 * @return string Sanitized query string for FTS5 MATCH clause.
	 */
	public static function sanitize_fts_query( string $query ): string {
		$trimmed = trim( $query );
		if ( empty( $trimmed ) ) {
			return '';
		}

		// Replace slashes in fractions (e.g. 199/165 -> 199_165 199 165)
		$expanded = preg_replace_callback(
			'/([a-zA-Z0-9]+)\s*[\/]\s*([a-zA-Z0-9]+)/',
			function( $matches ) {
				$first  = $matches[1];
				$second = $matches[2];
				return "{$first}_{$second} {$first} {$second}";
			},
			$trimmed
		);

		// Remove FTS5 reserved characters: " * ^ : ( ) - +
		$clean = preg_replace( '/[^\p{L}\p{N}_\s]/u', ' ', $expanded );

		// Collapse multiple whitespace
		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		if ( empty( $clean ) ) {
			return '';
		}

		// Form clean AND-delimited query tokens
		$words = explode( ' ', $clean );
		$tokens = array();
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( strlen( $word ) > 0 ) {
				$tokens[] = $word;
			}
		}

		return implode( ' ', $tokens );
	}

	/**
	 * Extract specific number intents from a query string for B-Tree fast path lookups.
	 *
	 * @param string $query User input.
	 * @return array{
	 *     has_number_intent: bool,
	 *     target_number: ?int,
	 *     target_clean: ?string,
	 *     target_total: ?int,
	 *     clean_keyword: string
	 * }
	 */
	public static function extract_number_intent( string $query ): array {
		$trimmed = trim( $query );
		$result = array(
			'has_number_intent' => false,
			'target_number'     => null,
			'target_clean'      => null,
			'target_total'      => null,
			'clean_keyword'     => $trimmed,
		);

		// Check for slash fraction (e.g. "charizard 199/165" or "199/165")
		if ( preg_match( '/^(.*?)\s*([A-Za-z0-9]+)\s*[\/]\s*(\d+)\s*$/', $trimmed, $matches ) ) {
			$result['has_number_intent'] = true;
			$result['clean_keyword']     = trim( $matches[1] );
			$result['target_clean']       = trim( $matches[2] );
			$result['target_total']       = (int) $matches[3];

			$num_only = preg_replace( '/\D/', '', $matches[2] );
			if ( $num_only !== '' ) {
				$result['target_number'] = (int) $num_only;
			}
			return $result;
		}

		// Check for trailing number (e.g. "charizard 4" or "charizard #004")
		if ( preg_match( '/^(.*?)\s+#?(\d+)\s*$/', $trimmed, $matches ) && ! empty( $matches[1] ) ) {
			$result['has_number_intent'] = true;
			$result['clean_keyword']     = trim( $matches[1] );
			$result['target_number']     = (int) $matches[2];
			$result['target_clean']      = $matches[2];
			return $result;
		}

		// Check for standalone number (e.g. "4", "004", "#4")
		if ( preg_match( '/^#?(\d+)$/', $trimmed, $matches ) ) {
			$result['has_number_intent'] = true;
			$result['clean_keyword']     = '';
			$result['target_number']     = (int) $matches[1];
			$result['target_clean']      = $matches[1];
			return $result;
		}

		return $result;
	}
}
