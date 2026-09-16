<?php
/**
 * MySQL Card Price History & Time-Series Engine.
 *
 * Manages daily historical price snapshots, batch upserts, portfolio valuation rollups,
 * and data retention pruning in WordPress MySQL.
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
	 * Get subsite table name for price history.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'card_vault_price_history';
	}

	/**
	 * Ensure the card_vault_price_history table and performance indexes exist.
	 *
	 * @param mixed $pdo Optional legacy parameter for backward compatibility.
	 */
	public static function ensure_table( $pdo = null ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::get_table_name();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			card_id varchar(64) NOT NULL,
			recorded_date date NOT NULL,
			raw_market decimal(10,2) NOT NULL DEFAULT 0.00,
			raw_low decimal(10,2) NOT NULL DEFAULT 0.00,
			raw_mid decimal(10,2) NOT NULL DEFAULT 0.00,
			raw_high decimal(10,2) NOT NULL DEFAULT 0.00,
			psa_9 decimal(10,2) NOT NULL DEFAULT 0.00,
			psa_10 decimal(10,2) NOT NULL DEFAULT 0.00,
			bgs_95 decimal(10,2) NOT NULL DEFAULT 0.00,
			cgc_10 decimal(10,2) NOT NULL DEFAULT 0.00,
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
	 * Record a single price snapshot for a card.
	 *
	 * Supports both ( $card_id, $pricing, $date ) and legacy ( $pdo, $card_id, $pricing, $date ).
	 *
	 * @param mixed ...$args Method arguments.
	 * @return bool True on success.
	 */
	public static function record_snapshot( ...$args ): bool {
		global $wpdb;

		if ( count( $args ) >= 2 && is_array( $args[1] ) ) {
			$card_id = (string) $args[0];
			$pricing = (array) $args[1];
			$date    = isset( $args[2] ) ? (string) $args[2] : null;
		} else {
			$card_id = isset( $args[1] ) ? (string) $args[1] : '';
			$pricing = isset( $args[2] ) ? (array) $args[2] : array();
			$date    = isset( $args[3] ) ? (string) $args[3] : null;
		}

		if ( empty( $card_id ) ) {
			return false;
		}

		$table       = self::get_table_name();
		$target_date = ! empty( $date ) ? $date : gmdate( 'Y-m-d' );
		$created_at  = time();

		$raw_market = (float) ( $pricing['raw_market'] ?? ( $pricing['market_price'] ?? 0.00 ) );
		$raw_low    = (float) ( $pricing['raw_low'] ?? ( $pricing['low_price'] ?? 0.00 ) );
		$raw_mid    = (float) ( $pricing['raw_mid'] ?? ( $pricing['mid_price'] ?? 0.00 ) );
		$raw_high   = (float) ( $pricing['raw_high'] ?? ( $pricing['high_price'] ?? 0.00 ) );
		$psa_9      = (float) ( $pricing['psa_9'] ?? ( $pricing['psa9_price'] ?? 0.00 ) );
		$psa_10     = (float) ( $pricing['psa_10'] ?? ( $pricing['psa10_price'] ?? 0.00 ) );
		$bgs_95     = (float) ( $pricing['bgs_95'] ?? ( $pricing['bgs95_price'] ?? 0.00 ) );
		$cgc_10     = (float) ( $pricing['cgc_10'] ?? ( $pricing['cgc10_price'] ?? 0.00 ) );
		$source     = (string) ( $pricing['source'] ?? 'tcgcsv' );

		$sql = "INSERT INTO {$table} (
			card_id, recorded_date, raw_market, raw_low, raw_mid, raw_high,
			psa_9, psa_10, bgs_95, cgc_10, source, created_at
		) VALUES (
			%s, %s, %f, %f, %f, %f,
			%f, %f, %f, %f, %s, %d
		) ON DUPLICATE KEY UPDATE
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

		$res = $wpdb->query( $wpdb->prepare(
			$sql,
			$card_id, $target_date, $raw_market, $raw_low, $raw_mid, $raw_high,
			$psa_9, $psa_10, $bgs_95, $cgc_10, $source, $created_at
		) );

		return false !== $res;
	}

	/**
	 * Record a batch of snapshots inside a single atomic bulk query.
	 *
	 * Supports both ( $snapshots, $date ) and legacy ( $pdo, $snapshots, $date ).
	 *
	 * @param mixed ...$args Method arguments.
	 * @return int Number of inserted/updated rows.
	 */
	public static function record_batch_snapshots( ...$args ): int {
		global $wpdb;

		if ( count( $args ) >= 1 && isset( $args[0] ) && is_array( $args[0] ) ) {
			$snapshots = (array) $args[0];
			$date      = isset( $args[1] ) ? (string) $args[1] : null;
		} else {
			$snapshots = isset( $args[1] ) ? (array) $args[1] : array();
			$date      = isset( $args[2] ) ? (string) $args[2] : null;
		}

		if ( empty( $snapshots ) ) {
			return 0;
		}

		$table       = self::get_table_name();
		$target_date = ! empty( $date ) ? $date : gmdate( 'Y-m-d' );
		$created_at  = time();
		$count       = 0;

		$chunks = array_chunk( $snapshots, 100 );
		foreach ( $chunks as $chunk ) {
			$placeholders = array();
			$values       = array();

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

			$sql = "INSERT INTO {$table} (
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

			$wpdb->query( $wpdb->prepare( $sql, $values ) );
		}

		return $count;
	}

	/**
	 * Retrieve chronological price history for a single card.
	 *
	 * Supports both ( $card_id, $days ) and legacy ( $pdo, $card_id, $days ).
	 *
	 * @param mixed ...$args Method arguments.
	 * @return array List of history points.
	 */
	public static function get_card_history( ...$args ): array {
		global $wpdb;

		if ( count( $args ) >= 1 && is_string( $args[0] ) ) {
			$card_id = (string) $args[0];
			$days    = isset( $args[1] ) ? (int) $args[1] : 30;
		} else {
			$card_id = isset( $args[1] ) ? (string) $args[1] : '';
			$days    = isset( $args[2] ) ? (int) $args[2] : 30;
		}

		if ( empty( $card_id ) ) {
			return array();
		}

		$table       = self::get_table_name();
		$days        = max( 1, min( 365, $days ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$sql = $wpdb->prepare(
			"SELECT
				recorded_date AS date,
				raw_market AS rawMarketPrice,
				raw_low AS rawLowPrice,
				raw_high AS rawHighPrice,
				psa_9 AS psa9Price,
				psa_10 AS psa10Price,
				source
			FROM {$table}
			WHERE card_id = %s AND recorded_date >= %s
			ORDER BY recorded_date ASC",
			$card_id,
			$cutoff_date
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( function( $row ) {
			return array(
				'date'           => (string) $row['date'],
				'rawMarketPrice' => (float) $row['rawMarketPrice'],
				'rawLowPrice'    => (float) $row['rawLowPrice'],
				'rawHighPrice'   => (float) $row['rawHighPrice'],
				'psa9Price'      => (float) $row['psa9Price'],
				'psa10Price'     => (float) $row['psa10Price'],
				'source'         => (string) $row['source'],
			);
		}, $rows );
	}

	/**
	 * Compute genuine historical portfolio valuation points from recorded snapshots.
	 *
	 * Supports both ( $items, $days ) and legacy ( $pdo, $items, $days ).
	 *
	 * @param mixed ...$args Method arguments.
	 * @return array List of PerformancePoints formatted for PortfolioSparkline.
	 */
	public static function get_portfolio_history( ...$args ): array {
		global $wpdb;

		if ( count( $args ) >= 1 && is_array( $args[0] ) ) {
			$items = (array) $args[0];
			$days  = isset( $args[1] ) ? (int) $args[1] : 30;
		} else {
			$items = isset( $args[1] ) ? (array) $args[1] : array();
			$days  = isset( $args[2] ) ? (int) $args[2] : 30;
		}

		if ( empty( $items ) ) {
			return array();
		}

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
			if ( 'LP' === $cond ) {
				$cond_multiplier = 0.85;
			} elseif ( 'MP' === $cond ) {
				$cond_multiplier = 0.70;
			} elseif ( 'HP' === $cond ) {
				$cond_multiplier = 0.50;
			} elseif ( 'DMG' === $cond ) {
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

		$table        = self::get_table_name();
		$card_ids     = array_keys( $card_map );
		$daily_totals = array();

		// Query history rows in chunks of 100
		$chunks = array_chunk( $card_ids, 100 );
		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$params       = array_merge( $chunk, array( $cutoff_date ) );
			$sql          = $wpdb->prepare(
				"SELECT card_id, recorded_date, raw_market, psa_10
				 FROM {$table}
				 WHERE card_id IN ({$placeholders}) AND recorded_date >= %s
				 ORDER BY recorded_date ASC",
				$params
			);

			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! empty( $rows ) ) {
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
			}
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
	 * Prune old daily records by compressing points older than $keep_daily_days to weekly points.
	 *
	 * Supports both ( $keep_daily_days ) and legacy ( $pdo, $keep_daily_days ).
	 *
	 * @param mixed ...$args Method arguments.
	 * @return int Rows removed.
	 */
	public static function prune_history( ...$args ): int {
		global $wpdb;

		if ( count( $args ) >= 1 && is_int( $args[0] ) ) {
			$keep_daily_days = (int) $args[0];
		} else {
			$keep_daily_days = isset( $args[1] ) ? (int) $args[1] : 90;
		}

		$table       = self::get_table_name();
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$keep_daily_days} days" ) );

		// Delete records older than cutoff that are NOT Sunday (DAYOFWEEK != 1 in MySQL)
		$sql = $wpdb->prepare(
			"DELETE FROM {$table}
			 WHERE recorded_date < %s
			 AND DAYOFWEEK(recorded_date) != 1",
			$cutoff_date
		);

		$deleted = $wpdb->query( $sql );
		return false !== $deleted ? (int) $deleted : 0;
	}
}
