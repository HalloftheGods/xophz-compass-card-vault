<?php
/**
 * SQLite Card Price History & Time-Series Engine.
 *
 * Manages daily historical price snapshots, batch upserts, portfolio valuation rollups,
 * and data retention pruning without polluting the WordPress MySQL database.
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

	const TABLE_NAME = 'card_price_history';

	/**
	 * Ensure the card_price_history table and performance indexes exist.
	 *
	 * @param PDO $pdo SQLite PDO instance.
	 */
	public static function ensure_table( PDO $pdo ): void {
		$pdo->exec( '
			CREATE TABLE IF NOT EXISTS card_price_history (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				card_id TEXT NOT NULL,
				recorded_date TEXT NOT NULL,
				raw_market REAL DEFAULT 0.00,
				raw_low REAL DEFAULT 0.00,
				raw_mid REAL DEFAULT 0.00,
				raw_high REAL DEFAULT 0.00,
				psa_9 REAL DEFAULT 0.00,
				psa_10 REAL DEFAULT 0.00,
				bgs_95 REAL DEFAULT 0.00,
				cgc_10 REAL DEFAULT 0.00,
				source TEXT NOT NULL,
				created_at INTEGER NOT NULL,
				UNIQUE(card_id, recorded_date)
			);
		' );

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_history_card_date ON card_price_history (card_id, recorded_date DESC);' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_history_date ON card_price_history (recorded_date);' );
	}

	/**
	 * Record a single price snapshot for a card.
	 *
	 * @param PDO         $pdo     SQLite PDO instance.
	 * @param string      $card_id Card identifier.
	 * @param array       $pricing Pricing associative array.
	 * @param string|null $date    YYYY-MM-DD date string (defaults to today).
	 * @return bool True on success.
	 */
	public static function record_snapshot( PDO $pdo, string $card_id, array $pricing, ?string $date = null ): bool {
		$target_date = ! empty( $date ) ? $date : gmdate( 'Y-m-d' );
		$created_at  = time();

		$sql = '
			INSERT INTO card_price_history (
				card_id, recorded_date, raw_market, raw_low, raw_mid, raw_high,
				psa_9, psa_10, bgs_95, cgc_10, source, created_at
			) VALUES (
				:card_id, :recorded_date, :raw_market, :raw_low, :raw_mid, :raw_high,
				:psa_9, :psa_10, :bgs_95, :cgc_10, :source, :created_at
			)
			ON CONFLICT(card_id, recorded_date) DO UPDATE SET
				raw_market = excluded.raw_market,
				raw_low    = excluded.raw_low,
				raw_mid    = excluded.raw_mid,
				raw_high   = excluded.raw_high,
				psa_9      = CASE WHEN excluded.psa_9 > 0 THEN excluded.psa_9 ELSE card_price_history.psa_9 END,
				psa_10     = CASE WHEN excluded.psa_10 > 0 THEN excluded.psa_10 ELSE card_price_history.psa_10 END,
				bgs_95     = CASE WHEN excluded.bgs_95 > 0 THEN excluded.bgs_95 ELSE card_price_history.bgs_95 END,
				cgc_10     = CASE WHEN excluded.cgc_10 > 0 THEN excluded.cgc_10 ELSE card_price_history.cgc_10 END,
				source     = excluded.source,
				created_at = excluded.created_at
		';

		$stmt = $pdo->prepare( $sql );
		return $stmt->execute( array(
			':card_id'       => $card_id,
			':recorded_date' => $target_date,
			':raw_market'    => (float) ( $pricing['raw_market'] ?? ( $pricing['market_price'] ?? 0.00 ) ),
			':raw_low'       => (float) ( $pricing['raw_low'] ?? ( $pricing['low_price'] ?? 0.00 ) ),
			':raw_mid'       => (float) ( $pricing['raw_mid'] ?? ( $pricing['mid_price'] ?? 0.00 ) ),
			':raw_high'      => (float) ( $pricing['raw_high'] ?? ( $pricing['high_price'] ?? 0.00 ) ),
			':psa_9'         => (float) ( $pricing['psa_9'] ?? ( $pricing['psa9_price'] ?? 0.00 ) ),
			':psa_10'        => (float) ( $pricing['psa_10'] ?? ( $pricing['psa10_price'] ?? 0.00 ) ),
			':bgs_95'        => (float) ( $pricing['bgs_95'] ?? ( $pricing['bgs95_price'] ?? 0.00 ) ),
			':cgc_10'        => (float) ( $pricing['cgc_10'] ?? ( $pricing['cgc10_price'] ?? 0.00 ) ),
			':source'        => (string) ( $pricing['source'] ?? 'tcgcsv' ),
			':created_at'    => $created_at,
		) );
	}

	/**
	 * Record a batch of snapshots inside a single atomic transaction.
	 *
	 * @param PDO   $pdo       SQLite PDO instance.
	 * @param array $snapshots Array of associative arrays with card_id and pricing.
	 * @param string|null $date
	 * @return int Number of inserted/updated rows.
	 */
	public static function record_batch_snapshots( PDO $pdo, array $snapshots, ?string $date = null ): int {
		if ( empty( $snapshots ) ) {
			return 0;
		}

		$target_date = ! empty( $date ) ? $date : gmdate( 'Y-m-d' );
		$created_at  = time();
		$count       = 0;

		$sql = '
			INSERT INTO card_price_history (
				card_id, recorded_date, raw_market, raw_low, raw_mid, raw_high,
				psa_9, psa_10, bgs_95, cgc_10, source, created_at
			) VALUES (
				:card_id, :recorded_date, :raw_market, :raw_low, :raw_mid, :raw_high,
				:psa_9, :psa_10, :bgs_95, :cgc_10, :source, :created_at
			)
			ON CONFLICT(card_id, recorded_date) DO UPDATE SET
				raw_market = excluded.raw_market,
				raw_low    = excluded.raw_low,
				raw_mid    = excluded.raw_mid,
				raw_high   = excluded.raw_high,
				psa_9      = CASE WHEN excluded.psa_9 > 0 THEN excluded.psa_9 ELSE card_price_history.psa_9 END,
				psa_10     = CASE WHEN excluded.psa_10 > 0 THEN excluded.psa_10 ELSE card_price_history.psa_10 END,
				bgs_95     = CASE WHEN excluded.bgs_95 > 0 THEN excluded.bgs_95 ELSE card_price_history.bgs_95 END,
				cgc_10     = CASE WHEN excluded.cgc_10 > 0 THEN excluded.cgc_10 ELSE card_price_history.cgc_10 END,
				source     = excluded.source,
				created_at = excluded.created_at
		';

		$stmt = $pdo->prepare( $sql );
		$pdo->beginTransaction();

		try {
			foreach ( $snapshots as $row ) {
				$card_id = (string) ( $row['card_id'] ?? ( $row['id'] ?? '' ) );
				if ( empty( $card_id ) ) {
					continue;
				}

				$stmt->execute( array(
					':card_id'       => $card_id,
					':recorded_date' => $target_date,
					':raw_market'    => (float) ( $row['raw_market'] ?? ( $row['market_price'] ?? 0.00 ) ),
					':raw_low'       => (float) ( $row['raw_low'] ?? ( $row['low_price'] ?? 0.00 ) ),
					':raw_mid'       => (float) ( $row['raw_mid'] ?? ( $row['mid_price'] ?? 0.00 ) ),
					':raw_high'      => (float) ( $row['raw_high'] ?? ( $row['high_price'] ?? 0.00 ) ),
					':psa_9'         => (float) ( $row['psa_9'] ?? ( $row['psa9_price'] ?? 0.00 ) ),
					':psa_10'        => (float) ( $row['psa_10'] ?? ( $row['psa10_price'] ?? 0.00 ) ),
					':bgs_95'        => (float) ( $row['bgs_95'] ?? ( $row['bgs95_price'] ?? 0.00 ) ),
					':cgc_10'        => (float) ( $row['cgc_10'] ?? ( $row['cgc10_price'] ?? 0.00 ) ),
					':source'        => (string) ( $row['source'] ?? 'tcgcsv' ),
					':created_at'    => $created_at,
				) );
				$count++;
			}
			$pdo->commit();
		} catch ( Exception $e ) {
			$pdo->rollBack();
			throw $e;
		}

		return $count;
	}

	/**
	 * Retrieve chronological price history for a single card.
	 *
	 * @param PDO    $pdo     SQLite PDO instance.
	 * @param string $card_id Card identifier.
	 * @param int    $days    Days of history to return (default: 30).
	 * @return array List of history points.
	 */
	public static function get_card_history( PDO $pdo, string $card_id, int $days = 30 ): array {
		$days = max( 1, min( 365, $days ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$sql = '
			SELECT
				recorded_date AS date,
				raw_market AS rawMarketPrice,
				raw_low AS rawLowPrice,
				raw_high AS rawHighPrice,
				psa_9 AS psa9Price,
				psa_10 AS psa10Price,
				source
			FROM card_price_history
			WHERE card_id = :card_id AND recorded_date >= :cutoff
			ORDER BY recorded_date ASC
		';

		$stmt = $pdo->prepare( $sql );
		$stmt->execute( array(
			':card_id' => $card_id,
			':cutoff'  => $cutoff_date,
		) );

		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Compute genuine historical portfolio valuation points from recorded snapshots.
	 *
	 * @param PDO   $pdo   SQLite PDO instance.
	 * @param array $items Array of inventory items: [{ card_id, quantity, condition, acquired_price }].
	 * @param int   $days  Number of days (default: 30).
	 * @return array List of PerformancePoints formatted for PortfolioSparkline.
	 */
	public static function get_portfolio_history( PDO $pdo, array $items, int $days = 30 ): array {
		if ( empty( $items ) ) {
			return array();
		}

		$days = max( 1, min( 365, $days ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// Extract card IDs and total cost basis
		$card_map = array();
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
			if ( $cond === 'LP' ) $cond_multiplier = 0.85;
			elseif ( $cond === 'MP' ) $cond_multiplier = 0.70;
			elseif ( $cond === 'HP' ) $cond_multiplier = 0.50;
			elseif ( $cond === 'DMG' ) $cond_multiplier = 0.25;

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

		// Query all history rows for these cards
		$placeholders = implode( ',', array_fill( 0, count( $card_map ), '?' ) );
		$sql = "
			SELECT card_id, recorded_date, raw_market, psa_10
			FROM card_price_history
			WHERE card_id IN ({$placeholders}) AND recorded_date >= ?
			ORDER BY recorded_date ASC
		";

		$params = array_merge( array_keys( $card_map ), array( $cutoff_date ) );
		$stmt   = $pdo->prepare( $sql );
		$stmt->execute( $params );
		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		// Group values by date
		$daily_totals = array();
		foreach ( $rows as $r ) {
			$dt  = $r['recorded_date'];
			$cid = $r['card_id'];
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

		$points = array();
		$month_names = array( '', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );

		foreach ( $daily_totals as $date_str => $totals ) {
			$ts = strtotime( $date_str );
			$m  = (int) gmdate( 'n', $ts );
			$d  = (int) gmdate( 'j', $ts );
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
	 * @param PDO $pdo             SQLite PDO instance.
	 * @param int $keep_daily_days Number of days to preserve exact daily snapshots.
	 * @return int Rows removed.
	 */
	public static function prune_history( PDO $pdo, int $keep_daily_days = 90 ): int {
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$keep_daily_days} days" ) );

		// Delete records older than cutoff that are NOT Sunday (strftime('%w') != '0')
		$sql = "
			DELETE FROM card_price_history
			WHERE recorded_date < :cutoff
			AND strftime('%w', recorded_date) != '0'
		";

		$stmt = $pdo->prepare( $sql );
		$stmt->execute( array( ':cutoff' => $cutoff_date ) );
		return $stmt->rowCount();
	}
}
