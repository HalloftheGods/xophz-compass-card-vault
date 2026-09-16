<?php
/**
 * SQLite High-Performance TCG Catalog Database Manager.
 *
 * Manages the local SQLite database connection, schema initialization,
 * WAL configuration, B-Tree indexes, and the two-stage smart search resolver.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Catalog_DB {

	const DB_FILENAME = 'cards.db';
	const UPLOADS_SUBDIR = 'card-vault';

	/**
	 * Singleton PDO instance.
	 *
	 * @var PDO|null
	 */
	private static ?PDO $pdo = null;

	/**
	 * Get the absolute filesystem path to the cards.db SQLite file.
	 *
	 * @return string Absolute file path.
	 */
	public static function get_db_path(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			$dir = trailingslashit( $upload_dir['basedir'] ) . self::UPLOADS_SUBDIR;
		} else {
			$dir = dirname( __DIR__ ) . '/data/' . self::UPLOADS_SUBDIR;
		}

		return $dir . '/' . self::DB_FILENAME;
	}

	/**
	 * Get the directory path for the card-vault uploads storage.
	 *
	 * @return string Directory path.
	 */
	public static function get_db_dir(): string {
		return dirname( self::get_db_path() );
	}

	/**
	 * Ensure storage directory exists with security guards.
	 */
	public static function ensure_storage_directory(): void {
		$dir = self::get_db_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Write .htaccess guard to block direct HTTP downloads
		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			@file_put_contents( $htaccess_file, "Order deny,allow\nDeny from all\n" );
		}

		// Write blank index.php fallback
		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Get or initialize PDO connection to SQLite database.
	 *
	 * @return PDO
	 * @throws PDOException If connection fails.
	 */
	public static function get_connection(): PDO {
		if ( self::$pdo !== null ) {
			return self::$pdo;
		}

		self::ensure_storage_directory();
		$path = self::get_db_path();

		$pdo = new PDO( 'sqlite:' . $path, null, null, array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_TIMEOUT            => 5,
		) );

		// Performance Pragmas
		$pdo->exec( 'PRAGMA journal_mode = WAL;' );
		$pdo->exec( 'PRAGMA synchronous = NORMAL;' );
		$pdo->exec( 'PRAGMA cache_size = -16000;' ); // 16MB page cache
		$pdo->exec( 'PRAGMA foreign_keys = ON;' );
		$pdo->exec( 'PRAGMA temp_store = MEMORY;' );

		self::$pdo = $pdo;
		return self::$pdo;
	}

	/**
	 * Initialize tables, B-tree indexes, and FTS5 structures.
	 */
	public static function ensure_database(): void {
		$pdo = self::get_connection();

		// 1. Core Cards Table
		$pdo->exec( '
			CREATE TABLE IF NOT EXISTS cards (
				id TEXT PRIMARY KEY,
				tcgplayer_id INTEGER UNIQUE NOT NULL,
				category_id INTEGER NOT NULL,
				category_name TEXT NOT NULL,
				group_id INTEGER NOT NULL,
				group_name TEXT NOT NULL,
				group_abbrev TEXT,
				name TEXT NOT NULL,
				clean_name TEXT NOT NULL,
				sub_type_name TEXT DEFAULT "Normal",

				raw_number TEXT,
				clean_number TEXT,
				numeric_number INTEGER,
				number_prefix TEXT,
				total_set_number INTEGER,
				number_variants TEXT,

				rarity TEXT,
				card_type TEXT,
				stage_or_subtype TEXT,
				hp INTEGER,
				card_text TEXT,
				upc TEXT,

				market_price REAL DEFAULT 0.00,
				low_price REAL DEFAULT 0.00,
				mid_price REAL DEFAULT 0.00,
				high_price REAL DEFAULT 0.00,
				direct_low_price REAL,
				psa9_price REAL DEFAULT 0.00,
				psa10_price REAL DEFAULT 0.00,
				bgs95_price REAL DEFAULT 0.00,
				cgc10_price REAL DEFAULT 0.00,
				pricing_source TEXT DEFAULT "tcgcsv",
				updated_at INTEGER NOT NULL,

				image_url TEXT,
				tcgplayer_url TEXT
			);
		' );

		// 1b. Schema column migration check for existing databases
		self::migrate_cards_columns( $pdo );

		// 1c. Initialize Price History Time-Series Table
		Card_Vault_Price_History::ensure_table( $pdo );

		// 2. High-Speed B-Tree Indexes
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_cards_numeric_lookup ON cards (numeric_number, clean_number);' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_cards_group_number ON cards (group_id, numeric_number);' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_cards_prefix_number ON cards (number_prefix, numeric_number);' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_cards_category_group ON cards (category_id, group_id);' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_cards_tcgplayer_id ON cards (tcgplayer_id);' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_cards_upc ON cards (upc);' );

		// 3. FTS5 Virtual Table
		$pdo->exec( '
			CREATE VIRTUAL TABLE IF NOT EXISTS cards_fts USING fts5(
				name,
				clean_name,
				group_name,
				number_variants,
				content="cards",
				content_rowid="tcgplayer_id",
				tokenize="unicode61 remove_diacritics 2"
			);
		' );

		// 4. Auto-Sync Triggers
		$pdo->exec( '
			CREATE TRIGGER IF NOT EXISTS cards_ai AFTER INSERT ON cards BEGIN
				INSERT INTO cards_fts(rowid, name, clean_name, group_name, number_variants)
				VALUES (new.tcgplayer_id, new.name, new.clean_name, new.group_name, new.number_variants);
			END;
		' );

		$pdo->exec( '
			CREATE TRIGGER IF NOT EXISTS cards_ad AFTER DELETE ON cards BEGIN
				INSERT INTO cards_fts(cards_fts, rowid, name, clean_name, group_name, number_variants)
				VALUES ("delete", old.tcgplayer_id, old.name, old.clean_name, old.group_name, old.number_variants);
			END;
		' );

		$pdo->exec( '
			CREATE TRIGGER IF NOT EXISTS cards_au AFTER UPDATE ON cards BEGIN
				INSERT INTO cards_fts(cards_fts, rowid, name, clean_name, group_name, number_variants)
				VALUES ("delete", old.tcgplayer_id, old.name, old.clean_name, old.group_name, old.number_variants);
				INSERT INTO cards_fts(rowid, name, clean_name, group_name, number_variants)
				VALUES (new.tcgplayer_id, new.name, new.clean_name, new.group_name, new.number_variants);
			END;
		' );
	}

	/**
	 * Two-Stage Smart Search Query Resolver.
	 *
	 * Executes deterministic B-Tree lookups for slash numbers (e.g. 199/165)
	 * and FTS5 BM25 searches for text keywords.
	 *
	 * @param string $query User search string.
	 * @param array  $filters Optional filters (category_id, group_id, rarity).
	 * @param int    $limit Max results (default: 25).
	 * @param int    $offset Result offset for pagination (default: 0).
	 * @return array List of matched cards.
	 */
	public static function search_cards( string $query, array $filters = array(), int $limit = 25, int $offset = 0 ): array {
		$pdo = self::get_connection();
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$trimmed = trim( $query );

		// 1. Check for Smart Number Intent
		$intent = Card_Vault_Number_Normalizer::extract_number_intent( $trimmed );

		// Fast Path A: Pure fraction query with no keyword (e.g. "199/165" or "004/102")
		if ( $intent['has_number_intent'] && empty( $intent['clean_keyword'] ) && $intent['target_total'] !== null ) {
			$sql = 'SELECT * FROM cards WHERE (numeric_number = :num OR clean_number = :clean) AND total_set_number = :total';
			$params = array(
				':num'   => $intent['target_number'],
				':clean' => $intent['target_clean'],
				':total' => $intent['target_total'],
			);

			if ( ! empty( $filters['category_id'] ) ) {
				$sql .= ' AND category_id = :cat';
				$params[':cat'] = (int) $filters['category_id'];
			}

			$sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
			$stmt = $pdo->prepare( $sql );
			$stmt->execute( $params );
			$results = $stmt->fetchAll();
			if ( ! empty( $results ) ) {
				return $results;
			}
		}

		// Fast Path B: Fraction with keyword (e.g. "Charizard 199/165" or "Charizard 4/102")
		if ( $intent['has_number_intent'] && ! empty( $intent['clean_keyword'] ) && $intent['target_total'] !== null ) {
			$clean_kw = Card_Vault_Number_Normalizer::sanitize_fts_query( $intent['clean_keyword'] );
			$token_pattern = "{$clean_kw}* AND ({$intent['target_clean']} OR {$intent['target_number']}_{$intent['target_total']})";

			$sql = '
				SELECT c.* FROM cards_fts f
				JOIN cards c ON c.tcgplayer_id = f.rowid
				WHERE cards_fts MATCH :match
			';
			$params = array( ':match' => $token_pattern );

			if ( ! empty( $filters['category_id'] ) ) {
				$sql .= ' AND c.category_id = :cat';
				$params[':cat'] = (int) $filters['category_id'];
			}

			$sql .= ' ORDER BY rank LIMIT ' . $limit . ' OFFSET ' . $offset;
			$stmt = $pdo->prepare( $sql );
			try {
				$stmt->execute( $params );
				$results = $stmt->fetchAll();
				if ( ! empty( $results ) ) {
					return $results;
				}
			} catch ( Exception $e ) {
				// Fall through to general search if FTS match pattern failed
			}
		}

		// Standard Path: Sanitized FTS5 Search
		$sanitized_fts = Card_Vault_Number_Normalizer::sanitize_fts_query( $trimmed );

		// If query is empty, browse all cards ordered by market_price DESC, name ASC
		if ( empty( $sanitized_fts ) ) {
			$sql = 'SELECT * FROM cards WHERE 1=1';
			$params = array();

			if ( ! empty( $filters['category_id'] ) ) {
				$sql .= ' AND category_id = :cat';
				$params[':cat'] = (int) $filters['category_id'];
			}

			if ( ! empty( $filters['group_id'] ) ) {
				$sql .= ' AND group_id = :group';
				$params[':group'] = (int) $filters['group_id'];
			}

			if ( ! empty( $filters['rarity'] ) ) {
				$sql .= ' AND rarity = :rarity';
				$params[':rarity'] = sanitize_text_field( $filters['rarity'] );
			}

			$sql .= ' ORDER BY market_price DESC, name ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
			$stmt = $pdo->prepare( $sql );
			$stmt->execute( $params );
			return $stmt->fetchAll();
		}

		// Build prefix match query (e.g. "charizard* AND 4*")
		$terms = explode( ' ', $sanitized_fts );
		$fts_query_parts = array();
		foreach ( $terms as $term ) {
			if ( strlen( $term ) > 0 ) {
				$fts_query_parts[] = "{$term}*";
			}
		}
		$fts_query = implode( ' AND ', $fts_query_parts );

		$sql = '
			SELECT c.* FROM cards_fts f
			JOIN cards c ON c.tcgplayer_id = f.rowid
			WHERE cards_fts MATCH :match
		';
		$params = array( ':match' => $fts_query );

		if ( ! empty( $filters['category_id'] ) ) {
			$sql .= ' AND c.category_id = :cat';
			$params[':cat'] = (int) $filters['category_id'];
		}

		if ( ! empty( $filters['group_id'] ) ) {
			$sql .= ' AND c.group_id = :group';
			$params[':group'] = (int) $filters['group_id'];
		}

		if ( ! empty( $filters['rarity'] ) ) {
			$sql .= ' AND c.rarity = :rarity';
			$params[':rarity'] = sanitize_text_field( $filters['rarity'] );
		}

		$sql .= ' ORDER BY rank LIMIT ' . $limit . ' OFFSET ' . $offset;

		try {
			$stmt = $pdo->prepare( $sql );
			$stmt->execute( $params );
			return $stmt->fetchAll();
		} catch ( Exception $e ) {
			// Fallback: LIKE query on title
			$like_sql = 'SELECT * FROM cards WHERE clean_name LIKE :like ORDER BY market_price DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
			$like_stmt = $pdo->prepare( $like_sql );
			$like_stmt->execute( array( ':like' => '%' . strtolower( $trimmed ) . '%' ) );
			return $like_stmt->fetchAll();
		}
	}

	/**
	 * Count total cards matching query and filters.
	 *
	 * @param string $query User search string.
	 * @param array  $filters Optional filters (category_id, group_id, rarity).
	 * @return int Total card count.
	 */
	public static function count_cards( string $query = '', array $filters = array() ): int {
		$pdo = self::get_connection();
		$trimmed = trim( $query );
		$sanitized_fts = Card_Vault_Number_Normalizer::sanitize_fts_query( $trimmed );

		if ( empty( $sanitized_fts ) ) {
			$sql = 'SELECT COUNT(*) FROM cards WHERE 1=1';
			$params = array();

			if ( ! empty( $filters['category_id'] ) ) {
				$sql .= ' AND category_id = :cat';
				$params[':cat'] = (int) $filters['category_id'];
			}

			if ( ! empty( $filters['group_id'] ) ) {
				$sql .= ' AND group_id = :group';
				$params[':group'] = (int) $filters['group_id'];
			}

			if ( ! empty( $filters['rarity'] ) ) {
				$sql .= ' AND rarity = :rarity';
				$params[':rarity'] = sanitize_text_field( $filters['rarity'] );
			}

			$stmt = $pdo->prepare( $sql );
			$stmt->execute( $params );
			return (int) $stmt->fetchColumn();
		}

		// FTS count
		$terms = explode( ' ', $sanitized_fts );
		$fts_query_parts = array();
		foreach ( $terms as $term ) {
			if ( strlen( $term ) > 0 ) {
				$fts_query_parts[] = "{$term}*";
			}
		}
		$fts_query = implode( ' AND ', $fts_query_parts );

		$sql = '
			SELECT COUNT(*) FROM cards_fts f
			JOIN cards c ON c.tcgplayer_id = f.rowid
			WHERE cards_fts MATCH :match
		';
		$params = array( ':match' => $fts_query );

		if ( ! empty( $filters['category_id'] ) ) {
			$sql .= ' AND c.category_id = :cat';
			$params[':cat'] = (int) $filters['category_id'];
		}

		if ( ! empty( $filters['group_id'] ) ) {
			$sql .= ' AND c.group_id = :group';
			$params[':group'] = (int) $filters['group_id'];
		}

		if ( ! empty( $filters['rarity'] ) ) {
			$sql .= ' AND c.rarity = :rarity';
			$params[':rarity'] = sanitize_text_field( $filters['rarity'] );
		}

		try {
			$stmt = $pdo->prepare( $sql );
			$stmt->execute( $params );
			return (int) $stmt->fetchColumn();
		} catch ( Exception $e ) {
			$like_sql = 'SELECT COUNT(*) FROM cards WHERE clean_name LIKE :like';
			$like_stmt = $pdo->prepare( $like_sql );
			$like_stmt->execute( array( ':like' => '%' . strtolower( $trimmed ) . '%' ) );
			return (int) $like_stmt->fetchColumn();
		}
	}

	/**
	 * Retrieve distinct synced groups/sets with card counts and timestamps.
	 *
	 * @return array List of synced set records.
	 */
	public static function get_synced_groups(): array {
		$pdo = self::get_connection();
		$sql = '
			SELECT
				group_id,
				group_name,
				category_id,
				category_name,
				COUNT(*) as card_count,
				MAX(updated_at) as last_synced
			FROM cards
			GROUP BY group_id
			ORDER BY last_synced DESC, group_name ASC
		';
		try {
			$stmt = $pdo->query( $sql );
			return $stmt->fetchAll() ?: array();
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Retrieve a single card by its canonical ID (e.g. "tcg-451620").
	 *
	 * @param string $id Card identifier.
	 * @return array|null Card record or null.
	 */
	public static function get_card_by_id( string $id ): ?array {
		$pdo = self::get_connection();
		$stmt = $pdo->prepare( 'SELECT * FROM cards WHERE id = :id LIMIT 1' );
		$stmt->execute( array( ':id' => $id ) );
		$card = $stmt->fetch();
		return $card ?: null;
	}

	/**
	 * Retrieve a single card by its TCGPlayer Product ID.
	 *
	 * @param int $tcgplayer_id Upstream Product ID.
	 * @return array|null Card record or null.
	 */
	public static function get_card_by_tcgplayer_id( int $tcgplayer_id ): ?array {
		$pdo = self::get_connection();
		$stmt = $pdo->prepare( 'SELECT * FROM cards WHERE tcgplayer_id = :pid LIMIT 1' );
		$stmt->execute( array( ':pid' => $tcgplayer_id ) );
		$card = $stmt->fetch();
		return $card ?: null;
	}

	/**
	 * Retrieve a product by its barcode (UPC for sealed items or product ID).
	 *
	 * @param string $barcode Scanned barcode string.
	 * @return array|null Card record or null.
	 */
	public static function get_card_by_barcode( string $barcode ): ?array {
		$pdo = self::get_connection();
		$trimmed = trim( $barcode );

		// 1. Try exact UPC match (Sealed booster box / pack)
		$stmt = $pdo->prepare( 'SELECT * FROM cards WHERE upc = :upc LIMIT 1' );
		$stmt->execute( array( ':upc' => $trimmed ) );
		$card = $stmt->fetch();
		if ( $card ) {
			return $card;
		}

		// 2. Try clean leading zeros on UPC
		$clean_upc = ltrim( $trimmed, '0' );
		if ( $clean_upc !== $trimmed ) {
			$stmt = $pdo->prepare( 'SELECT * FROM cards WHERE upc = :upc OR upc = :clean LIMIT 1' );
			$stmt->execute( array( ':upc' => $trimmed, ':clean' => $clean_upc ) );
			$card = $stmt->fetch();
			if ( $card ) {
				return $card;
			}
		}

		// 3. Try TCGPlayer ID numeric lookup
		if ( is_numeric( $trimmed ) ) {
			return self::get_card_by_tcgplayer_id( (int) $trimmed );
		}

		return null;
	}

	/**
	 * Retrieve fresh pricing metrics for multiple cards in batch.
	 *
	 * Queries SQLite cards.db using indexed primary keys and TCGPlayer IDs.
	 *
	 * @param array $card_identifiers List of card objects or string IDs.
	 * @return array Map of card_id => pricing attributes.
	 */
	public static function get_cards_pricing_batch( array $card_identifiers ): array {
		$pdo = self::get_connection();
		$results = array();
		if ( empty( $card_identifiers ) ) {
			return $results;
		}

		$ids = array();
		$tcgplayer_ids = array();

		foreach ( $card_identifiers as $item ) {
			if ( is_string( $item ) ) {
				$ids[] = sanitize_text_field( trim( $item ) );
			} elseif ( is_array( $item ) ) {
				if ( ! empty( $item['id'] ) ) {
					$ids[] = sanitize_text_field( trim( (string) $item['id'] ) );
				}
				if ( ! empty( $item['tcgplayerId'] ) || ! empty( $item['tcgplayer_id'] ) ) {
					$pid = (int) ( $item['tcgplayerId'] ?? $item['tcgplayer_id'] );
					if ( $pid > 0 ) {
						$tcgplayer_ids[] = $pid;
					}
				}
			}
		}

		// 1. Query by direct IDs
		if ( ! empty( $ids ) ) {
			$ids = array_values( array_unique( $ids ) );
			$chunks = array_chunk( $ids, 100 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '?' ) );
				$stmt = $pdo->prepare( "SELECT id, tcgplayer_id, name, market_price, low_price, mid_price, high_price, psa10_price, psa9_price, updated_at FROM cards WHERE id IN ($placeholders)" );
				$stmt->execute( $chunk );
				$rows = $stmt->fetchAll();
				foreach ( $rows as $row ) {
					$results[ $row['id'] ] = array(
						'id'             => $row['id'],
						'tcgplayerId'    => (int) $row['tcgplayer_id'],
						'rawMarketPrice' => (float) $row['market_price'],
						'rawLowPrice'    => (float) $row['low_price'],
						'rawMidPrice'    => (float) $row['mid_price'],
						'rawHighPrice'   => (float) $row['high_price'],
						'psa10Price'     => (float) $row['psa10_price'],
						'psa9Price'      => (float) $row['psa9_price'],
						'psa8Price'      => round( (float) $row['market_price'] * 0.90, 2 ),
						'psa7Price'      => round( (float) $row['market_price'] * 0.72, 2 ),
						'lastUpdated'    => ! empty( $row['updated_at'] ) ? date( 'Y-m-d', (int) $row['updated_at'] ) : gmdate( 'Y-m-d' ),
					);
				}
			}
		}

		// 2. Query by tcgplayer_ids for missing items
		if ( ! empty( $tcgplayer_ids ) ) {
			$tcgplayer_ids = array_values( array_unique( $tcgplayer_ids ) );
			$chunks = array_chunk( $tcgplayer_ids, 100 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '?' ) );
				$stmt = $pdo->prepare( "SELECT id, tcgplayer_id, name, market_price, low_price, mid_price, high_price, psa10_price, psa9_price, updated_at FROM cards WHERE tcgplayer_id IN ($placeholders)" );
				$stmt->execute( $chunk );
				$rows = $stmt->fetchAll();
				foreach ( $rows as $row ) {
					$pricing = array(
						'id'             => $row['id'],
						'tcgplayerId'    => (int) $row['tcgplayer_id'],
						'rawMarketPrice' => (float) $row['market_price'],
						'rawLowPrice'    => (float) $row['low_price'],
						'rawMidPrice'    => (float) $row['mid_price'],
						'rawHighPrice'   => (float) $row['high_price'],
						'psa10Price'     => (float) $row['psa10_price'],
						'psa9Price'      => (float) $row['psa9_price'],
						'psa8Price'      => round( (float) $row['market_price'] * 0.90, 2 ),
						'psa7Price'      => round( (float) $row['market_price'] * 0.72, 2 ),
						'lastUpdated'    => ! empty( $row['updated_at'] ) ? date( 'Y-m-d', (int) $row['updated_at'] ) : gmdate( 'Y-m-d' ),
					);
					if ( ! isset( $results[ $row['id'] ] ) ) {
						$results[ $row['id'] ] = $pricing;
					}
					$results[ (string) $row['tcgplayer_id'] ] = $pricing;
				}
			}
		}

		return $results;
	}

	/**
	 * Run column migrations on existing cards table if upgrading from earlier schema.
	 *
	 * @param PDO $pdo SQLite PDO instance.
	 */
	private static function migrate_cards_columns( PDO $pdo ): void {
		try {
			$cols = $pdo->query( 'PRAGMA table_info(cards)' )->fetchAll( PDO::FETCH_COLUMN, 1 );
			$new_cols = array(
				'psa9_price'     => 'REAL DEFAULT 0.00',
				'psa10_price'    => 'REAL DEFAULT 0.00',
				'bgs95_price'    => 'REAL DEFAULT 0.00',
				'cgc10_price'    => 'REAL DEFAULT 0.00',
				'pricing_source' => "TEXT DEFAULT 'tcgcsv'",
			);

			foreach ( $new_cols as $col_name => $col_def ) {
				if ( ! in_array( $col_name, $cols, true ) ) {
					$pdo->exec( "ALTER TABLE cards ADD COLUMN {$col_name} {$col_def};" );
				}
			}
		} catch ( Exception $e ) {
			// PRAGMA query may fail if table doesn't exist yet; safe to ignore
		}
	}

	/**
	 * Retrieve chronological price history for a card.
	 *
	 * @param string $card_id Card identifier.
	 * @param int    $days    Number of days of history.
	 * @return array
	 */
	public static function get_card_price_history( string $card_id, int $days = 30 ): array {
		$pdo = self::get_connection();
		return Card_Vault_Price_History::get_card_history( $pdo, $card_id, $days );
	}

	/**
	 * Retrieve portfolio valuation history across multiple cards.
	 *
	 * @param array $items List of inventory items with card_id, quantity, condition, acquired_price.
	 * @param int   $days  Number of days of history.
	 * @return array List of PerformancePoints.
	 */
	public static function get_portfolio_history( array $items, int $days = 30 ): array {
		$pdo = self::get_connection();
		return Card_Vault_Price_History::get_portfolio_history( $pdo, $items, $days );
	}

	/**
	 * Get database diagnostics and metrics.
	 *
	 * @return array Database status details.
	 */
	public static function get_status(): array {
		$path = self::get_db_path();
		$exists = file_exists( $path );
		$size_bytes = $exists ? filesize( $path ) : 0;
		$wal_path = $path . '-wal';
		$wal_bytes = file_exists( $wal_path ) ? filesize( $wal_path ) : 0;

		$total_cards = 0;
		$last_updated = null;

		if ( $exists ) {
			try {
				$pdo = self::get_connection();
				$total_cards = (int) $pdo->query( 'SELECT COUNT(*) FROM cards' )->fetchColumn();
				$max_epoch = (int) $pdo->query( 'SELECT MAX(updated_at) FROM cards' )->fetchColumn();
				if ( $max_epoch > 0 ) {
					$last_updated = date( 'Y-m-d H:i:s', $max_epoch );
				}
			} catch ( Exception $e ) {
				// Ignore query errors during uninitialized state
			}
		}

		$synced_groups = $exists ? self::get_synced_groups() : array();

		return array(
			'exists'         => $exists,
			'path'           => $path,
			'size_bytes'     => $size_bytes,
			'size_mb'        => round( $size_bytes / ( 1024 * 1024 ), 2 ),
			'wal_bytes'      => $wal_bytes,
			'total_cards'    => $total_cards,
			'last_updated'   => $last_updated,
			'driver'         => 'SQLite PDO',
			'synced_groups'  => $synced_groups,
			'total_groups'   => count( $synced_groups ),
		);
	}
}
