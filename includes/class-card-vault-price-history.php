<?php
/**
 * Subsite-Isolated MySQL Card Price History & Time-Series Engine.
 *
 * Manages daily historical price snapshots, batch upserts, portfolio valuation rollups,
 * and data retention pruning using WordPress MySQL tables.
 *
 * Adheres to Chemical X Standards:
 * - Single-responsibility capsule, zero synthetic mock data, zero em dashes.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Price_History {

	const TABLE_NAME = 'card_vault_price_history';

	/**
	 * Get the subsite-isolated price history table name.
	 *
	 * @return string Full table name with subsite prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Ensure the card_vault_price_history table and performance indexes exist in MySQL.
	 * Accepts optional legacy parameter for backward compatibility.
	 *
	 * @param mixed $legacy_context Optional legacy context.
	 */
	public static function ensure_table( $legacy_context = null ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			card_id varchar(64) NOT NULL,
			recorded_date date NOT NULL,
			raw_market decimal(10,2) DEFAULT 0.00,
			raw_low decimal(10,2) DEFAULT 0.00,
			raw_mid decimal(10,2) DEFAULT 0.00,
			raw_high decimal(10,2) DEFAULT 0.00,
			psa_9 decimal(10,2) DEFAULT 0.00,
			psa_10 decimal(10,2) DEFAULT 0.00,
			bgs_95 decimal(10,2) DEFAULT 0.00,
			cgc_10 decimal(10,2) DEFAULT 0.00,
			source varchar(50) NOT NULL DEFAULT 'tcgcsv',
			created_at bigint(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_card_date (card_id, recorded_date),
			KEY idx_history_card_date (card_id, recorded_date),
			KEY idx_history_date (recorded_date)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Record a single price snapshot for a card in MySQL.
	 * Supports both signatures: record_snapshot($card_id, $pricing, $date) and legacy record_snapshot($pdo, $card_id, $pricing, $date).
	 *
	 * @param mixed       $arg1        Card ID or legacy PDO instance.
	 * @param mixed       $arg2        Pricing array or Card ID.
	 * @param mixed       $arg3        Date string or Pricing array.
	 * @param string|null $date_param  Optional explicit date string.
	 * @return bool True on success.
	 */
	public static function record_snapshot( $arg1, $arg2 = array(), $arg3 = null, ?string $date_param = null ): bool {
		global $wpdb;

		$card_id = is_string( $arg1 ) ? $arg1 : (string) $arg2;
		$pricing = is_array( $arg2 ) ? $arg2 : ( is_array( $arg3 ) ? $arg3 : array() );
		$target_date = is_string( $arg3 ) ? $arg3 : ( ! empty( $date_param ) ? $date_param : gmdate( 'Y-m-d' ) );
		$created_at  = time();
		$table       = self::get_table_name();

		$raw_market = (float) ( $pricing['raw_market'] ?? ( $pricing['market_price'] ?? 0.00 ) );
		$raw_low    = (float) ( $pricing['raw_low'] ?? ( $pricing['low_price'] ?? 0.00 ) );
		$raw_mid    = (float) ( $pricing['raw_mid'] ?? ( $pricing['mid_price'] ?? 0.00 ) );
		$raw_high   = (float) ( $pricing['raw_high'] ?? ( $pricing['high_price'] ?? 0.00 ) );
		$psa_9      = (float) ( $pricing['psa_9'] ?? ( $pricing['psa9_price'] ?? 0.00 ) );
		$psa_10     = (float) ( $pricing['psa_10'] ?? ( $pricing['psa10_price'] ?? 0.00 ) );
		$bgs_95     = (float) ( $pricing['bgs_95'] ?? ( $pricing['bgs95_price'] ?? 0.00 ) );
		$cgc_10     = (float) ( $pricing['cgc_10'] ?? ( $pricing['cgc10_price'] ?? 0.00 ) );
		$source     = (string) ( $pricing['source'] ?? 'tcgcsv' );

		$query = "INSERT INTO {$table} (
				card_id, recorded_date, raw_market, raw_low, raw_mid, raw_high,
				psa_9, psa_10, bgs_95, cgc_10, source, created_at
			) VALUES (%s, %s, %f, %f, %f, %f, %f, %f, %f, %f, %s, %d)
			ON DUPLICATE KEY UPDATE
				raw_market = VALUES(raw_market),
				raw_low    = VALUES(raw_low),
				raw_mid    = VALUES(raw_mid),
				raw_high   = VALUES(raw_high),
				psa_9      = CASE WHEN VALUES(psa_9) > 0 THEN VALUES(psa_9) ELSE psa_9 END,
				psa_10     = CASE WHEN VALUES(psa_10) > 0 THEN VALUES(psa_10) ELSE psa_10 END,
				bgs_95     = CASE WHEN VALUES(bgs_95) > 0 THEN VALUES(bgs_95) ELSE bgs_95 END,
				cgc_10     = CASE WHEN VALUES(cgc_10) > 0 THEN VALUES(cgc_10) ELSE cgc_10 END,
				source     = VALUES(source),
				created_at = VALUES(created_at)";

		$prepared = $wpdb->prepare(
			$query,
			$card_id, $target_date, $raw_market, $raw_low, $raw_mid, $raw_high,
			$psa_9, $psa_10, $bgs_95, $cgc_10, $source, $created_at
		);

		return (bool) $wpdb->query( $prepared );
	}

	/**
	 * Record a batch of snapshots inside MySQL via chunked ON DUPLICATE KEY UPDATE.
	 * Supports both record_batch_snapshots($snapshots, $date) and legacy record_batch_snapshots($pdo, $snapshots, $date).
	 *
	 * @param mixed       $arg1       Snapshots array or legacy PDO instance.
	 * @param mixed       $arg2       Optional date string or snapshots array.
	 * @param string|null $date_param Optional explicit date string.
	 * @return int Number of inserted/updated rows.
	 */
	public static function record_batch_snapshots( $arg1, $arg2 = null, ?string $date_param = null ): int {
		$snapshots = is_array( $arg1 ) ? $arg1 : ( is_array( $arg2 ) ? $arg2 : array() );
		if ( empty( $snapshots ) ) {
			return 0;
		}

		global $wpdb;
		$table       = self::get_table_name();
		$target_date = is_string( $arg2 ) ? $arg2 : ( ! empty( $date_param ) ? $date_param : gmdate( 'Y-m-d' ) );
		$created_at  = time();
		$count       = 0;

		$chunks = array_chunk( $snapshots, 200 );
		foreach ( $chunks as $chunk ) {
			$values       = array();
			$placeholders = array();

			foreach ( $chunk as $row ) {
				$card_id = (string) ( $row['card_id'] ?? ( $row['id'] ?? '' ) );
				if ( empty( $card_id ) ) {
					continue;
				}

				$placeholders[] = '(%s, %s, %f, %f, %f, %f, %f, %f, %f, %f, %s, %d)';
				$values[]       = $card_id;
				$values[]       = $target_date;
				$values[]       = (float) ( $row['raw_market'] ?? ( $row['market_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['raw_low'] ?? ( $row['low_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['raw_mid'] ?? ( $row['mid_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['raw_high'] ?? ( $row['high_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['psa_9'] ?? ( $row['psa9_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['psa_10'] ?? ( $row['psa10_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['bgs_95'] ?? ( $row['bgs95_price'] ?? 0.00 ) );
				$values[]       = (float) ( $row['cgc_10'] ?? ( $row['cgc10_price'] ?? 0.00 ) );
				$values[]       = (string) ( $row['source'] ?? 'tcgcsv' );
				$values[]       = $created_at;
				$count++;
			}

			if ( empty( $placeholders ) ) {
				continue;
			}

			$query = "INSERT INTO {$table} (
					card_id, recorded_date, raw_market, raw_low, raw_mid, raw_high,
					psa_9, psa_10, bgs_95, cgc_10, source, created_at
				) VALUES " . implode( ', ', $placeholders ) . "
				ON DUPLICATE KEY UPDATE
					raw_market = VALUES(raw_market),
					raw_low    = VALUES(raw_low),
					raw_mid    = VALUES(raw_mid),
					raw_high   = VALUES(raw_high),
					psa_9      = CASE WHEN VALUES(psa_9) > 0 THEN VALUES(psa_9) ELSE psa_9 END,
					psa_10     = CASE WHEN VALUES(psa_10) > 0 THEN VALUES(psa_10) ELSE psa_10 END,
					bgs_95     = CASE WHEN VALUES(bgs_95) > 0 THEN VALUES(bgs_95) ELSE bgs_95 END,
					cgc_10     = CASE WHEN VALUES(cgc_10) > 0 THEN VALUES(cgc_10) ELSE cgc_10 END,
					source     = VALUES(source),
					created_at = VALUES(created_at)";

			$wpdb->query( $wpdb->prepare( $query, $values ) );
		}

		return $count;
	}

	/**
	 * Retrieve chronological price history for a single card from MySQL.
	 * Supports both get_card_history($card_id, $days) and legacy get_card_history($pdo, $card_id, $days).
	 *
	 * @param mixed $arg1       Card identifier or legacy PDO instance.
	 * @param mixed $arg2       Days of history (int) or Card identifier (string).
	 * @param int   $days_param Days of history (default: 30).
	 * @return array List of history points.
	 */
	public static function get_card_history( $arg1, $arg2 = null, int $days_param = 30 ): array {
		global $wpdb;

		$card_id = is_string( $arg1 ) ? $arg1 : (string) $arg2;
		$days    = is_int( $arg2 ) ? $arg2 : $days_param;
		$days    = max( 1, min( 365, $days ) );

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$table       = self::get_table_name();

		$sql = "
			SELECT
				recorded_date AS date,
				raw_market AS rawMarketPrice,
				raw_low AS rawLowPrice,
				raw_high AS rawHighPrice,
				psa_9 AS psa9Price,
				psa_10 AS psa10Price,
				source
			FROM {$table}
			WHERE card_id = %s AND recorded_date >= %s
			ORDER BY recorded_date ASC
		";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $card_id, $cutoff_date ), ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$r ) {
			$r['rawMarketPrice'] = (float) $r['rawMarketPrice'];
			$r['rawLowPrice']    = (float) $r['rawLowPrice'];
			$r['rawHighPrice']   = (float) $r['rawHighPrice'];
			$r['psa9Price']      = (float) $r['psa9Price'];
			$r['psa10Price']     = (float) $r['psa10Price'];
		}

		return $rows;
	}

	/**
	 * Compute genuine historical portfolio valuation points from recorded MySQL snapshots.
	 * Supports both get_portfolio_history($items, $days) and legacy get_portfolio_history($pdo, $items, $days).
	 *
	 * @param mixed $arg1       Array of inventory items or legacy PDO instance.
	 * @param mixed $arg2       Days (int) or items array.
	 * @param int   $days_param Number of days (default: 30).
	 * @return array List of PerformancePoints formatted for PortfolioSparkline.
	 */
	public static function get_portfolio_history( $arg1, $arg2 = null, int $days_param = 30 ): array {
		$items = is_array( $arg1 ) ? $arg1 : ( is_array( $arg2 ) ? $arg2 : array() );
		$days  = is_int( $arg2 ) ? $arg2 : $days_param;

		if ( empty( $items ) ) {
			return array();
		}

		global $wpdb;
		$table       = self::get_table_name();
		$days        = max( 1, min( 365, $days ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Extract card IDs and total cost basis
		$card_map         = array();
		$total_cost_basis = 0.00;

		foreach ( $items as $item ) {
			$cid = (string) ( $item['card_id'] ?? ( $item['cardId'] ?? '' ) );
			if ( empty( $cid ) ) {
				continue;
			}
			$qty  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$cost = (float) ( $item['acquired_price'] ?? ( $item['acquiredPrice'] ?? 0.00 ) );
			$cond = (string) ( $item['condition'] ?? 'NM' );

			// Multiplier for condition
			$cond_multiplier = 1.0;
			if ( $cond === 'LP' ) {
				$cond_multiplier = 0.85;
			} elseif ( $cond === 'MP' ) {
				$cond_multiplier = 0.70;
			} elseif ( $cond === 'HP' ) {
				$cond_multiplier = 0.50;
			} elseif ( $cond === 'DMG' ) {
				$cond_multiplier = 0.25;
			}

			if ( ! isset( $card_map[ $cid ] ) ) {
				$card_map[ $cid ] = array(
					'qty'             => $qty,
					'cond_multiplier' => $cond_multiplier,
				);
			} else {
				$card_map[ $cid ]['qty'] += $qty;
			}

			$total_cost_basis += ( $cost * $qty );
		}

		if ( empty( $card_map ) ) {
			return array();
		}

		$card_ids     = array_keys( $card_map );
		$placeholders = implode( ',', array_fill( 0, count( $card_ids ), '%s' ) );
		$sql          = "
			SELECT card_id, recorded_date, raw_market, psa_10
			FROM {$table}
			WHERE card_id IN ({$placeholders}) AND recorded_date >= %s
			ORDER BY recorded_date ASC
		";

		$params = array_merge( $card_ids, array( $cutoff_date ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		// Group values by date
		$daily_totals = array();
		foreach ( $rows as $r ) {
			$dt  = (string) $r['recorded_date'];
			$cid = (string) $r['card_id'];
			$cfg = $card_map[ $cid ] ?? array( 'qty' => 1, 'cond_multiplier' => 1.0 );

			if ( ! isset( $daily_totals[ $dt ] ) ) {
				$daily_totals[ $dt ] = array(
					'raw'   => 0.00,
					'psa10' => 0.00,
				);
			}

			$raw_market = (float) $r['raw_market'];
			$psa_10     = (float) $r['psa_10'];

			$daily_totals[ $dt ]['raw']   += ( $raw_market * $cfg['cond_multiplier'] * $cfg['qty'] );
			$daily_totals[ $dt ]['psa10'] += ( $psa_10 * $cfg['qty'] );
		}

		ksort( $daily_totals );

		$points      = array();
		$month_names = array( '', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );

		foreach ( $daily_totals as $date_str => $totals ) {
			$ts    = strtotime( $date_str );
			$m     = (int) gmdate( 'n', $ts );
			$d     = (int) gmdate( 'j', $ts );
			$label = "{$month_names[$m]} {$d}";

			$market_val = round( $totals['raw'], 2 );
			$top_graded = round( $totals['psa10'], 2 );
			$gain       = round( $market_val - $total_cost_basis, 2 );
			$roi        = $total_cost_basis > 0 ? round( ( $gain / $total_cost_basis ) * 100, 1 ) : 0.0;

			$points[] = array(
				'date'           => $date_str,
				'label'          => $label,
				'marketValue'    => $market_val,
				'costBasis'      => round( $total_cost_basis, 2 ),
				'unrealizedGain' => $gain,
				'roiPercent'     => $roi,
				'topGradedValue' => $top_graded,
			);
		}

		return $points;
	}

	/**
	 * Prune old daily records by compressing points older than $keep_daily_days to weekly points in MySQL.
	 * Preserves Sundays (DAYOFWEEK = 1).
	 * Supports both prune_history($days) and legacy prune_history($pdo, $days).
	 *
	 * @param mixed $arg1       Days (int) or legacy PDO instance.
	 * @param int   $days_param Number of days to preserve exact daily snapshots (default: 90).
	 * @return int Rows removed.
	 */
	public static function prune_history( $arg1 = null, int $days_param = 90 ): int {
		$days        = is_int( $arg1 ) ? $arg1 : $days_param;
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		global $wpdb;
		$table = self::get_table_name();

		$sql = $wpdb->prepare(
			"DELETE FROM {$table} WHERE recorded_date < %s AND DAYOFWEEK(recorded_date) != 1",
			$cutoff_date
		);

		return (int) $wpdb->query( $sql );
	}
}
