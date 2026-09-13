<?php
/**
 * Card Vault Local WebP Image Cache Manager.
 *
 * Provides high-speed disk caching of TCG catalog card images converted to WebP.
 * Fully compatible with WPMU DEV managed hosting environments by using native
 * WordPress APIs (wp_get_image_editor, wp_upload_dir, wp_remote_get) without
 * shell exec or external binary dependencies.
 *
 * Strict Global Hygiene:
 * - Zero em dashes: hyphens or colons only.
 * - Max lines under 500.
 * - 2-stage atomic boolean composition.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Image_Cache {

	const CACHE_SUBDIR = 'card-vault-cache/images';
	const WEBP_QUALITY = 85;
	const WEBP_THUMB_QUALITY = 78;
	const MAX_DOWNLOAD_SIZE_BYTES = 10485760; // 10MB limit

	/**
	 * Ensure directory structure and security/caching headers are initialized.
	 */
	public static function init(): void {
		self::ensure_cache_directory();
	}

	/**
	 * Resolve the base cache directory path.
	 *
	 * @return string Absolute filesystem path.
	 */
	public static function get_cache_dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			$base_dir   = trailingslashit( $upload_dir['basedir'] );
		} else {
			$base_dir = dirname( __DIR__ ) . '/data/';
		}

		return trailingslashit( $base_dir . self::CACHE_SUBDIR );
	}

	/**
	 * Resolve the base cache public URL.
	 *
	 * @return string Absolute public URL.
	 */
	public static function get_cache_url(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir();
			$base_url   = trailingslashit( $upload_dir['baseurl'] );
		} else {
			$base_url = content_url( 'uploads/' );
		}

		return trailingslashit( $base_url . self::CACHE_SUBDIR );
	}

	/**
	 * Ensure cache storage directory exists with security guards and asset allowances.
	 */
	public static function ensure_cache_directory(): void {
		$dir = self::get_cache_dir();
		$dir_exists = is_dir( $dir );
		if ( ! $dir_exists ) {
			wp_mkdir_p( $dir );
		}

		// Write Apache .htaccess guard: deny script execution, allow static images with caching headers
		$htaccess_file = $dir . '.htaccess';
		$has_htaccess = file_exists( $htaccess_file );
		if ( ! $has_htaccess ) {
			$rules = implode( "\n", array(
				'<FilesMatch "\.(php|phtml|php3|php4|php5|phps)$">',
				'  Order Deny,Allow',
				'  Deny from all',
				'</FilesMatch>',
				'<FilesMatch "\.(webp|png|jpe?g|svg|gif)$">',
				'  Order Allow,Deny',
				'  Allow from all',
				'  <IfModule mod_expires.c>',
				'    ExpiresActive On',
				'    ExpiresDefault "access plus 1 month"',
				'  </IfModule>',
				'  <IfModule mod_headers.c>',
				'    Header set Cache-Control "public, max-age=2592000, immutable"',
				'  </IfModule>',
				'</FilesMatch>',
				'',
			) );
			@file_put_contents( $htaccess_file, $rules );
		}

		// Write blank index.php fallback
		$index_file = $dir . 'index.php';
		$has_index = file_exists( $index_file );
		if ( ! $has_index ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Construct the relative storage path for a card image.
	 *
	 * @param string $card_id Card identifier.
	 * @param int    $group_id Optional group/set ID for folder partitioning.
	 * @param string $ext Target extension ('webp', 'png', etc.).
	 * @param bool   $is_thumbnail Whether this is a thumbnail variant.
	 * @return string Relative path.
	 */
	public static function get_relative_path( string $card_id, int $group_id = 0, string $ext = 'webp', bool $is_thumbnail = false ): string {
		$safe_card_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $card_id );
		$safe_group   = max( 0, $group_id );
		$suffix       = $is_thumbnail ? '_sm' : '';
		$clean_ext    = ltrim( $ext, '.' );

		return "{$safe_group}/{$safe_card_id}{$suffix}.{$clean_ext}";
	}

	/**
	 * Get absolute filesystem path for a card image.
	 *
	 * @param string $card_id Card identifier.
	 * @param int    $group_id Group/set ID.
	 * @param string $ext Extension.
	 * @param bool   $is_thumbnail Thumbnail flag.
	 * @return string Absolute file path.
	 */
	public static function get_file_path( string $card_id, int $group_id = 0, string $ext = 'webp', bool $is_thumbnail = false ): string {
		$rel = self::get_relative_path( $card_id, $group_id, $ext, $is_thumbnail );
		return self::get_cache_dir() . $rel;
	}

	/**
	 * Get public URL for a cached card image.
	 *
	 * @param string $card_id Card identifier.
	 * @param int    $group_id Group/set ID.
	 * @param string $ext Extension.
	 * @param bool   $is_thumbnail Thumbnail flag.
	 * @return string Public URL.
	 */
	public static function get_file_url( string $card_id, int $group_id = 0, string $ext = 'webp', bool $is_thumbnail = false ): string {
		$rel = self::get_relative_path( $card_id, $group_id, $ext, $is_thumbnail );
		return self::get_cache_url() . $rel;
	}

	/**
	 * Check if a card image is already cached locally.
	 *
	 * @param string $card_id Card identifier.
	 * @param int    $group_id Group/set ID.
	 * @param bool   $is_thumbnail Thumbnail flag.
	 * @return bool True if cached file exists and is non-empty.
	 */
	public static function is_cached( string $card_id, int $group_id = 0, bool $is_thumbnail = false ): bool {
		$webp_path = self::get_file_path( $card_id, $group_id, 'webp', $is_thumbnail );
		$has_webp  = file_exists( $webp_path ) && filesize( $webp_path ) > 0;
		if ( $has_webp ) {
			return true;
		}

		// Fallback check for uncompressed png/jpg in case webp was unavailable
		$png_path = self::get_file_path( $card_id, $group_id, 'png', $is_thumbnail );
		$has_png  = file_exists( $png_path ) && filesize( $png_path ) > 0;
		if ( $has_png ) {
			return true;
		}

		$jpg_path = self::get_file_path( $card_id, $group_id, 'jpg', $is_thumbnail );
		return file_exists( $jpg_path ) && filesize( $jpg_path ) > 0;
	}

	/**
	 * Retrieve existing cached URL if present.
	 *
	 * @param string $card_id Card identifier.
	 * @param int    $group_id Group/set ID.
	 * @param bool   $is_thumbnail Thumbnail flag.
	 * @return string|null URL if cached, null otherwise.
	 */
	public static function get_cached_image_url( string $card_id, int $group_id = 0, bool $is_thumbnail = false ): ?string {
		$webp_path = self::get_file_path( $card_id, $group_id, 'webp', $is_thumbnail );
		$has_webp  = file_exists( $webp_path ) && filesize( $webp_path ) > 0;
		if ( $has_webp ) {
			return self::get_file_url( $card_id, $group_id, 'webp', $is_thumbnail );
		}

		$png_path = self::get_file_path( $card_id, $group_id, 'png', $is_thumbnail );
		$has_png  = file_exists( $png_path ) && filesize( $png_path ) > 0;
		if ( $has_png ) {
			return self::get_file_url( $card_id, $group_id, 'png', $is_thumbnail );
		}

		$jpg_path = self::get_file_path( $card_id, $group_id, 'jpg', $is_thumbnail );
		$has_jpg  = file_exists( $jpg_path ) && filesize( $jpg_path ) > 0;
		if ( $has_jpg ) {
			return self::get_file_url( $card_id, $group_id, 'jpg', $is_thumbnail );
		}

		return null;
	}

	/**
	 * Download a remote card image, transcode to WebP (via WordPress Image Editor),
	 * and store in the local isolated cache directory.
	 *
	 * @param string $remote_url Remote image URL.
	 * @param string $card_id Card identifier.
	 * @param int    $group_id Group/set ID.
	 * @param bool   $is_thumbnail Thumbnail flag.
	 * @return string Public URL of the cached image, or empty string on failure.
	 */
	public static function cache_remote_image( string $remote_url, string $card_id, int $group_id = 0, bool $is_thumbnail = false ): string {
		$trimmed_url = trim( $remote_url );
		$is_empty_url = empty( $trimmed_url );
		if ( $is_empty_url ) {
			return '';
		}

		// Stage 1: Check existing cache
		$existing_url = self::get_cached_image_url( $card_id, $group_id, $is_thumbnail );
		$is_already_cached = ! empty( $existing_url );
		if ( $is_already_cached ) {
			return $existing_url;
		}

		// Stage 2: Validate URL syntax and protocol
		$is_valid_url = (bool) wp_http_validate_url( $trimmed_url );
		if ( ! $is_valid_url ) {
			return '';
		}

		// Stage 3: Fetch remote image stream
		$response = wp_remote_get( $trimmed_url, array(
			'timeout'     => 15,
			'redirection' => 5,
			'sslverify'   => true,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) CardVaultCache/1.0',
		) );

		$is_error_response = is_wp_error( $response );
		if ( $is_error_response ) {
			return '';
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$is_http_ok    = $response_code >= 200 && $response_code < 300;
		if ( ! $is_http_ok ) {
			return '';
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$body_length = strlen( $raw_body );
		$is_valid_body_size = $body_length > 100 && $body_length <= self::MAX_DOWNLOAD_SIZE_BYTES;
		if ( ! $is_valid_body_size ) {
			return '';
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$target_webp_path = self::get_file_path( $card_id, $group_id, 'webp', $is_thumbnail );
		$target_dir       = dirname( $target_webp_path );

		$has_target_dir = is_dir( $target_dir );
		if ( ! $has_target_dir ) {
			wp_mkdir_p( $target_dir );
		}

		// Path A: Remote asset is ALREADY WebP (e.g. TCGdex). Save directly with zero transcoding overhead.
		$is_already_webp = str_contains( $content_type, 'image/webp' ) || str_ends_with( strtolower( $trimmed_url ), '.webp' );
		if ( $is_already_webp ) {
			@file_put_contents( $target_webp_path, $raw_body );
			return self::get_file_url( $card_id, $group_id, 'webp', $is_thumbnail );
		}

		// Path B: Remote asset is PNG or JPEG. Transcode to WebP via WP_Image_Editor.
		$temp_file = wp_tempnam( 'cv_img_' );
		if ( ! $temp_file ) {
			return '';
		}

		@file_put_contents( $temp_file, $raw_body );

		// Check if WordPress Image Editor supports WebP generation
		$supports_webp = function_exists( 'wp_image_editor_supports' ) &&
			wp_image_editor_supports( array( 'methods' => array( 'save' ), 'mime_type' => 'image/webp' ) );

		if ( $supports_webp ) {
			$editor = wp_get_image_editor( $temp_file );
			$is_editor_ready = ! is_wp_error( $editor );

			if ( $is_editor_ready ) {
				$quality = $is_thumbnail ? self::WEBP_THUMB_QUALITY : self::WEBP_QUALITY;
				$editor->set_quality( $quality );

				$saved = $editor->save( $target_webp_path, 'image/webp' );
				$is_save_successful = ! is_wp_error( $saved ) && file_exists( $target_webp_path );

				if ( $is_save_successful ) {
					@unlink( $temp_file );
					return self::get_file_url( $card_id, $group_id, 'webp', $is_thumbnail );
				}
			}
		}

		// Path C: Graceful fallback for hosting environments lacking WebP encoders.
		// Save original image format so card rendering never fails.
		$detected_ext = 'png';
		if ( str_contains( $content_type, 'jpeg' ) || str_contains( $content_type, 'jpg' ) ) {
			$detected_ext = 'jpg';
		}

		$fallback_path = self::get_file_path( $card_id, $group_id, $detected_ext, $is_thumbnail );
		@copy( $temp_file, $fallback_path );
		@unlink( $temp_file );

		$has_fallback_saved = file_exists( $fallback_path );
		if ( $has_fallback_saved ) {
			return self::get_file_url( $card_id, $group_id, $detected_ext, $is_thumbnail );
		}

		return '';
	}

	/**
	 * Compute disk usage statistics for the local image cache.
	 *
	 * @return array Metrics regarding cached image count and disk size.
	 */
	public static function get_cache_stats(): array {
		$dir = self::get_cache_dir();
		$dir_exists = is_dir( $dir );
		if ( ! $dir_exists ) {
			return array(
				'total_files' => 0,
				'total_bytes' => 0,
				'size_human'  => '0 MB',
			);
		}

		$total_files = 0;
		$total_bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$name = $file->getFilename();
				$is_asset = ! in_array( $name, array( '.htaccess', 'index.php' ), true );
				if ( $is_asset ) {
					$total_files++;
					$total_bytes += $file->getSize();
				}
			}
		}

		$mb = round( $total_bytes / 1048576, 2 );

		return array(
			'total_files' => $total_files,
			'total_bytes' => $total_bytes,
			'size_human'  => "{$mb} MB",
		);
	}

	/**
	 * Purge all cached image files from disk.
	 *
	 * @return array Deletion summary.
	 */
	public static function purge_cache(): array {
		$dir = self::get_cache_dir();
		$dir_exists = is_dir( $dir );
		if ( ! $dir_exists ) {
			return array( 'purged_count' => 0 );
		}

		$purged_count = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$name = $item->getFilename();
			$is_system_file = in_array( $name, array( '.htaccess', 'index.php' ), true );
			if ( $is_system_file ) {
				continue;
			}

			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
				$purged_count++;
			}
		}

		return array( 'purged_count' => $purged_count );
	}
}
