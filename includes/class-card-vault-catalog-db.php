<?php
/**
 * Subsite-Isolated MySQL High-Performance TCG Catalog Database Manager.
 *
 * Manages the WordPress MySQL database tables for Card Vault catalog,
 * subsite prefix isolation, B-Tree and FULLTEXT indexes, two-stage smart search,
 * and selective category/set ingestion.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Catalog_DB {

	const OPTION_SELECTED_CATEGORIES = 'card_vault_catalog_selected_categories';
	const OPTION_SETS_SCOPE          = 'card_vault_catalog_sets_scope';

	/**
	 * Get the subsite-isolated cards table name.
	 *
	 * @return string Full table name with subsite prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'card_vault_cards';
	}

	/**
	 * Get the subsite-isolated sync sets table name.
	 *
	 * @return string Full table name with subsite prefix.
	 */
	public static function get_sets_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'card_vault_sync_sets';
	}

	/**
	 * Compatibility stub for legacy SQLite path callers.
	 *
	 * @return string Empty or legacy path string.
	 */
	public static function get_db_path(): string {
		return '';
	}

	/**
	 * Compatibility stub for legacy SQLite dir callers.
	 *
	 * @return string Directory path.
	 */
	public static function get_db_dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			return trailingslashit( $upload_dir['basedir'] ) . 'card-vault';
		}
		return dirname( __DIR__ ) . '/data/card-vault';
	}

	/**
	 * Compatibility stub for storage directory checks.
	 */
	public static function ensure_storage_directory(): void {
		$dir = self::get_db_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * Compatibility stub for legacy get_connection callers.
	 *
	 * @return null
	 */
	public static function get_connection() {
		return null;
	}

	/**
	 * Initialize MySQL tables, B-tree indexes, and FULLTEXT structures.
	 */
	public static function ensure_database(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$cards_table     = self::get_table_name();
		$sets_table      = self::get_sets_table_name();

		$sql_cards = "CREATE TABLE {$cards_table} (
			id varchar(64) NOT NULL,
			tcgplayer_id bigint(20) UNSIGNED NOT NULL,
			category_id bigint(20) UNSIGNED NOT NULL,
			category_name varchar(100) NOT NULL,
			group_id bigint(20) UNSIGNED NOT NULL,
			group_name varchar(150) NOT NULL,
			group_abbrev varchar(50) DEFAULT NULL,
			name varchar(255) NOT NULL,
			clean_name varchar(255) NOT NULL,
			sub_type_name varchar(100) DEFAULT 'Normal',
			raw_number varchar(50) DEFAULT NULL,
			clean_number varchar(50) DEFAULT NULL,
			numeric_number int(11) DEFAULT NULL,
			number_prefix varchar(20) DEFAULT NULL,
			total_set_number int(11) DEFAULT NULL,
			number_variants text DEFAULT NULL,
			rarity varchar(100) DEFAULT NULL,
			card_type varchar(100) DEFAULT NULL,
			stage_or_subtype varchar(100) DEFAULT NULL,
			hp int(11) DEFAULT NULL,
			card_text text DEFAULT NULL,
			upc varchar(50) DEFAULT NULL,
			market_price decimal(10,2) DEFAULT 0.00,
			low_price decimal(10,2) DEFAULT 0.00,
			mid_price decimal(10,2) DEFAULT 0.00,
			high_price decimal(10,2) DEFAULT 0.00,
			direct_low_price decimal(10,2) DEFAULT NULL,
			updated_at bigint(20) NOT NULL,
			image_url text DEFAULT NULL,
			tcgplayer_url text DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tcgplayer_id (tcgplayer_id),
			KEY idx_numeric_lookup (numeric_number, clean_number),
			KEY idx_group_number (group_id, numeric_number),
			KEY idx_category_group (category_id, group_id),
			KEY idx_upc (upc)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_sets = "CREATE TABLE {$sets_table} (
			group_id bigint(20) UNSIGNED NOT NULL,
			category_id bigint(20) UNSIGNED NOT NULL,
			group_name varchar(150) NOT NULL,
			category_name varchar(100) NOT NULL,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			priority int(11) NOT NULL DEFAULT 10,
			card_count int(11) NOT NULL DEFAULT 0,
			last_synced_at datetime DEFAULT NULL,
			sync_status varchar(50) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (group_id),
			KEY idx_cat_enabled (category_id, is_enabled),
			KEY idx_priority (priority, group_id)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_cards );
		dbDelta( $sql_sets );

		// Ensure FULLTEXT index exists for text searches (dbDelta does not manage FULLTEXT)
		$existing_indexes = $wpdb->get_results( "SHOW INDEX FROM {$cards_table} WHERE Key_name = 'ft_card_search'", ARRAY_A );
		if ( empty( $existing_indexes ) ) {
			$wpdb->query( "ALTER TABLE {$cards_table} ADD FULLTEXT KEY ft_card_search (name, clean_name, group_name)" );
		}
	}

	/**
	 * Two-Stage Smart Search Query Resolver in MySQL.
	 *
	 * Executes deterministic B-Tree lookups for slash numbers (e.g. 199/165)
	 * and FULLTEXT boolean searches with LIKE fallback for text keywords.
	 *
	 * @param string $query User search string.
	 * @param array  $filters Optional filters (category_id, group_id, rarity).
	 * @param int    $limit Max results (default: 25).
	 * @param int    $offset Result offset for pagination (default: 0).
	 * @return array List of matched cards.
	 */
	public static function search_cards( string $query, array $filters = array(), int $limit = 25, int $offset = 0 ): array {
		global $wpdb;
		$table   = self::get_table_name();
		$limit   = max( 1, min( 100, $limit ) );
		$offset  = max( 0, $offset );
		$trimmed = trim( $query );

		// 1. Check for Smart Number Intent
		$intent = Card_Vault_Number_Normalizer::extract_number_intent( $trimmed );

		// Fast Path A: Pure fraction query with no keyword (e.g. "199/165")
		if ( $intent['has_number_intent'] && empty( $intent['clean_keyword'] ) && $intent['target_total'] !== null ) {
			$where = '(numeric_number = %d OR clean_number = %s) AND total_set_number = %d';
			$args  = array( $intent['target_number'], $intent['target_clean'], $intent['target_total'] );

			if ( ! empty( $filters['category_id'] ) ) {
				$where .= ' AND category_id = %d';
				$args[] = (int) $filters['category_id'];
			}

			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
			$results = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! empty( $results ) ) {
				return $results;
			}
		}

		// Fast Path B: Fraction with keyword (e.g. "Charizard 199/165")
		if ( $intent['has_number_intent'] && ! empty( $intent['clean_keyword'] ) && $intent['target_total'] !== null ) {
			$clean_kw = preg_replace( '/[+\-><()~*\"@]+/', '', $intent['clean_keyword'] );
			$where    = 'MATCH(name, clean_name, group_name) AGAINST(%s IN BOOLEAN MODE) AND (numeric_number = %d OR clean_number = %s) AND total_set_number = %d';
			$args     = array( "+{$clean_kw}*", $intent['target_number'], $intent['target_clean'], $intent['target_total'] );

			if ( ! empty( $filters['category_id'] ) ) {
				$where .= ' AND category_id = %d';
				$args[] = (int) $filters['category_id'];
			}

			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
			$results = $wpdb->get_results( $sql, ARRAY_A );
			if ( ! empty( $results ) ) {
				return $results;
			}
		}

		// Standard Path: Empty query browses all cards
		if ( empty( $trimmed ) ) {
			$where = '1=1';
			$args  = array();

			if ( ! empty( $filters['category_id'] ) ) {
				$where .= ' AND category_id = %d';
				$args[] = (int) $filters['category_id'];
			}
			if ( ! empty( $filters['group_id'] ) ) {
				$where .= ' AND group_id = %d';
				$args[] = (int) $filters['group_id'];
			}
			if ( ! empty( $filters['rarity'] ) ) {
				$where .= ' AND rarity = %s';
				$args[] = sanitize_text_field( $filters['rarity'] );
			}

			$sql = empty( $args )
				? $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY market_price DESC, name ASC LIMIT %d OFFSET %d", $limit, $offset )
				: $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY market_price DESC, name ASC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
			return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
		}

		// Standard Path: FULLTEXT Boolean search
		$terms = array_filter( explode( ' ', $trimmed ), fn( $t ) => strlen( trim( $t ) ) > 0 );
		$ft_query = '';
		foreach ( $terms as $term ) {
			$cleaned_term = preg_replace( '/[+\-><()~*\"@]+/', '', $term );
			if ( strlen( $cleaned_term ) > 0 ) {
				$ft_query .= '+' . $cleaned_term . '* ';
			}
		}
		$ft_query = trim( $ft_query );

		$where = 'MATCH(name, clean_name, group_name) AGAINST(%s IN BOOLEAN MODE)';
		$args  = array( $ft_query );

		if ( ! empty( $filters['category_id'] ) ) {
			$where .= ' AND category_id = %d';
			$args[] = (int) $filters['category_id'];
		}
		if ( ! empty( $filters['group_id'] ) ) {
			$where .= ' AND group_id = %d';
			$args[] = (int) $filters['group_id'];
		}
		if ( ! empty( $filters['rarity'] ) ) {
			$where .= ' AND rarity = %s';
			$args[] = sanitize_text_field( $filters['rarity'] );
		}

		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY market_price DESC, name ASC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
		$results = $wpdb->get_results( $sql, ARRAY_A );

		// Fallback to LIKE if FULLTEXT yields no results
		if ( empty( $results ) ) {
			$like_val   = '%' . $wpdb->esc_like( strtolower( $trimmed ) ) . '%';
			$like_where = '(clean_name LIKE %s OR group_name LIKE %s)';
			$like_args  = array( $like_val, $like_val );
			if ( ! empty( $filters['category_id'] ) ) {
				$like_where .= ' AND category_id = %d';
				$like_args[] = (int) $filters['category_id'];
			}
			if ( ! empty( $filters['group_id'] ) ) {
				$like_where .= ' AND group_id = %d';
				$like_args[] = (int) $filters['group_id'];
			}
			$like_sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE {$like_where} ORDER BY market_price DESC LIMIT %d OFFSET %d", array_merge( $like_args, array( $limit, $offset ) ) );
			$results  = $wpdb->get_results( $like_sql, ARRAY_A );
		}

		return $results ?: array();
	}

	/**
	 * Count total cards matching query and filters.
	 *
	 * @param string $query User search string.
	 * @param array  $filters Optional filters.
	 * @return int Total count.
	 */
	public static function count_cards( string $query = '', array $filters = array() ): int {
		global $wpdb;
		$table   = self::get_table_name();
		$trimmed = trim( $query );

		if ( empty( $trimmed ) ) {
			$where = '1=1';
			$args  = array();
			if ( ! empty( $filters['category_id'] ) ) {
				$where .= ' AND category_id = %d';
				$args[] = (int) $filters['category_id'];
			}
			if ( ! empty( $filters['group_id'] ) ) {
				$where .= ' AND group_id = %d';
				$args[] = (int) $filters['group_id'];
			}
			if ( ! empty( $filters['rarity'] ) ) {
				$where .= ' AND rarity = %s';
				$args[] = sanitize_text_field( $filters['rarity'] );
			}
			$sql = empty( $args ) ? "SELECT COUNT(*) FROM {$table}" : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args );
			return (int) $wpdb->get_var( $sql );
		}

		$terms = array_filter( explode( ' ', $trimmed ), fn( $t ) => strlen( trim( $t ) ) > 0 );
		$ft_query = '';
		foreach ( $terms as $term ) {
			$cleaned_term = preg_replace( '/[+\-><()~*\"@]+/', '', $term );
			if ( strlen( $cleaned_term ) > 0 ) {
				$ft_query .= '+' . $cleaned_term . '* ';
			}
		}
		$ft_query = trim( $ft_query );

		$where = 'MATCH(name, clean_name, group_name) AGAINST(%s IN BOOLEAN MODE)';
		$args  = array( $ft_query );
		if ( ! empty( $filters['category_id'] ) ) {
			$where .= ' AND category_id = %d';
			$args[] = (int) $filters['category_id'];
		}
		if ( ! empty( $filters['group_id'] ) ) {
			$where .= ' AND group_id = %d';
			$args[] = (int) $filters['group_id'];
		}
		if ( ! empty( $filters['rarity'] ) ) {
			$where .= ' AND rarity = %s';
			$args[] = sanitize_text_field( $filters['rarity'] );
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) );
		if ( $count === 0 ) {
			$like_val   = '%' . $wpdb->esc_like( strtolower( $trimmed ) ) . '%';
			$like_where = '(clean_name LIKE %s OR group_name LIKE %s)';
			$like_args  = array( $like_val, $like_val );
			if ( ! empty( $filters['category_id'] ) ) {
				$like_where .= ' AND category_id = %d';
				$like_args[] = (int) $filters['category_id'];
			}
			if ( ! empty( $filters['group_id'] ) ) {
				$like_where .= ' AND group_id = %d';
				$like_args[] = (int) $filters['group_id'];
			}
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$like_where}", $like_args ) );
		}
		return $count;
	}

	/**
	 * Retrieve distinct synced groups with card counts and timestamps.
	 *
	 * @return array List of synced set records.
	 */
	public static function get_synced_groups(): array {
		global $wpdb;
		$table = self::get_table_name();

		// Self-heal table if not yet created
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::ensure_database();
		}

		$sql = "
			SELECT
				group_id,
				group_name,
				category_id,
				category_name,
				COUNT(*) as card_count,
				MAX(updated_at) as last_synced
			FROM {$table}
			GROUP BY group_id, group_name, category_id, category_name
			ORDER BY last_synced DESC, group_name ASC
		";
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Retrieve a single card by its canonical ID.
	 *
	 * @param string $id Card identifier.
	 * @return array|null Card record or null.
	 */
	public static function get_card_by_id( string $id ): ?array {
		global $wpdb;
		$table = self::get_table_name();
		$card  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s LIMIT 1", $id ), ARRAY_A );
		return $card ?: null;
	}

	/**
	 * Retrieve a single card by its TCGPlayer Product ID.
	 *
	 * @param int $tcgplayer_id Upstream Product ID.
	 * @return array|null Card record or null.
	 */
	public static function get_card_by_tcgplayer_id( int $tcgplayer_id ): ?array {
		global $wpdb;
		$table = self::get_table_name();
		$card  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tcgplayer_id = %d LIMIT 1", $tcgplayer_id ), ARRAY_A );
		return $card ?: null;
	}

	/**
	 * Retrieve a product by barcode (UPC or TCGPlayer ID).
	 *
	 * @param string $barcode Scanned barcode string.
	 * @return array|null Card record or null.
	 */
	public static function get_card_by_barcode( string $barcode ): ?array {
		global $wpdb;
		$table   = self::get_table_name();
		$trimmed = trim( $barcode );

		$card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE upc = %s LIMIT 1", $trimmed ), ARRAY_A );
		if ( $card ) {
			return $card;
		}

		$clean_upc = ltrim( $trimmed, '0' );
		if ( $clean_upc !== $trimmed ) {
			$card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE upc = %s OR upc = %s LIMIT 1", $trimmed, $clean_upc ), ARRAY_A );
			if ( $card ) {
				return $card;
			}
		}

		if ( is_numeric( $trimmed ) ) {
			return self::get_card_by_tcgplayer_id( (int) $trimmed );
		}

		return null;
	}

	/**
	 * Batch upsert an array of card records via MySQL ON DUPLICATE KEY UPDATE.
	 *
	 * @param array<array> $cards List of prepared card row arrays.
	 * @return int Total number of records processed.
	 */
	public static function batch_upsert_cards( array $cards ): int {
		global $wpdb;
		if ( empty( $cards ) ) {
			return 0;
		}

		$table   = self::get_table_name();
		$columns = array(
			'id', 'tcgplayer_id', 'category_id', 'category_name', 'group_id', 'group_name',
			'name', 'clean_name', 'sub_type_name',
			'raw_number', 'clean_number', 'numeric_number', 'number_prefix', 'total_set_number', 'number_variants',
			'rarity', 'card_type', 'stage_or_subtype', 'hp', 'card_text', 'upc',
			'market_price', 'low_price', 'mid_price', 'high_price', 'direct_low_price',
			'updated_at', 'image_url', 'tcgplayer_url',
		);

		$col_list       = implode( ', ', $columns );
		$update_clauses = array();
		foreach ( array( 'category_name', 'group_name', 'name', 'clean_name', 'sub_type_name', 'raw_number', 'clean_number', 'numeric_number', 'number_prefix', 'total_set_number', 'number_variants', 'rarity', 'card_type', 'stage_or_subtype', 'hp', 'card_text', 'upc', 'market_price', 'low_price', 'mid_price', 'high_price', 'direct_low_price', 'updated_at', 'image_url', 'tcgplayer_url' ) as $up_col ) {
			$update_clauses[] = "{$up_col} = VALUES({$up_col})";
		}
		$update_str     = implode( ', ', $update_clauses );
		$chunks         = array_chunk( $cards, 200 );
		$total_inserted = 0;

		foreach ( $chunks as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $card ) {
				$placeholders[] = '(' . implode( ', ', array_fill( 0, count( $columns ), '%s' ) ) . ')';
				foreach ( $columns as $col ) {
					$values[] = $card[ $col ] ?? null;
				}
			}

			$query = "INSERT INTO {$table} ({$col_list}) VALUES " . implode( ', ', $placeholders ) . " ON DUPLICATE KEY UPDATE {$update_str}";
			$wpdb->query( $wpdb->prepare( $query, $values ) );
			$total_inserted += count( $chunk );
		}

		return $total_inserted;
	}

	/**
	 * Get subsite database diagnostics and metrics.
	 *
	 * @return array Database status details.
	 */
	public static function get_status(): array {
		global $wpdb;
		$table      = self::get_table_name();
		$table_name = str_replace( '`', '', $table );
		$db_name    = DB_NAME;

		// Ensure table exists on status check
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::ensure_database();
		}

		$info = $wpdb->get_row( $wpdb->prepare(
			'SELECT TABLE_ROWS, (DATA_LENGTH + INDEX_LENGTH) as bytes
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$db_name,
			$table_name
		), ARRAY_A );

		$exists       = ! empty( $info );
		$size_bytes   = (int) ( $info['bytes'] ?? 0 );
		$total_cards  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$max_epoch    = (int) $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" );
		$last_updated = $max_epoch > 0 ? date( 'Y-m-d H:i:s', $max_epoch ) : null;

		$synced_groups = self::get_synced_groups();

		return array(
			'exists'        => $exists,
			'table'         => $table,
			'size_bytes'    => $size_bytes,
			'size_mb'       => round( $size_bytes / ( 1024 * 1024 ), 2 ),
			'total_cards'   => $total_cards,
			'last_updated'  => $last_updated,
			'driver'        => 'WordPress MySQL (InnoDB)',
			'synced_groups' => $synced_groups,
			'total_groups'  => count( $synced_groups ),
		);
	}

	/**
	 * Get subsite selected categories for catalog synchronization.
	 * Defaults to Pokémon (category 3) if not yet configured.
	 *
	 * @return array<int> List of category IDs.
	 */
	public static function get_selected_categories(): array {
		$saved = get_option( self::OPTION_SELECTED_CATEGORIES, array( 3 ) );
		return is_array( $saved ) && ! empty( $saved ) ? array_map( 'intval', $saved ) : array( 3 );
	}

	/**
	 * Update subsite selected categories.
	 *
	 * @param array<int> $category_ids List of category IDs.
	 */
	public static function set_selected_categories( array $category_ids ): void {
		$clean = array_unique( array_filter( array_map( 'intval', $category_ids ) ) );
		update_option( self::OPTION_SELECTED_CATEGORIES, $clean );
	}

	/**
	 * Get sets scope setting (e.g. 10, 25, 50, or 'all').
	 *
	 * @return string Sets scope value.
	 */
	public static function get_sets_scope(): string {
		return (string) get_option( self::OPTION_SETS_SCOPE, '25' );
	}

	/**
	 * Update sets scope setting.
	 *
	 * @param string $scope Scope value.
	 */
	public static function set_sets_scope( string $scope ): void {
		$clean = sanitize_text_field( $scope );
		update_option( self::OPTION_SETS_SCOPE, $clean );
	}
}
