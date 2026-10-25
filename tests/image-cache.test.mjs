/**
 * Card Vault image cache tests.
 * No mock card data: stubs only stand in for WordPress HTTP and path helpers; the image is a generated WebP header.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

describe('Image cache', () => {
  it('cache_remote_image returns empty when the WebP cannot be written', () => {
    const res = runPhpJson(`
      define('ABSPATH', '/var/www/html/');
      $GLOBALS['base'] = sys_get_temp_dir() . '/cv-img-' . uniqid();
      mkdir( $GLOBALS['base'] );
      // A regular file where the cache directory should be: mkdir and writes under it must fail.
      file_put_contents( $GLOBALS['base'] . '/card-vault-cache', 'x' );
      function apply_filters( $t, $v ) { return $v; }
      function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
      function wp_parse_url( $u ) { return parse_url( $u ); }
      function wp_http_validate_url( $u ) { return $u; }
      function wp_mkdir_p( $d ) { return @mkdir( $d, 0777, true ); }
      function is_wp_error( $r ) { return false; }
      function wp_upload_dir() { return array( 'basedir' => $GLOBALS['base'], 'baseurl' => 'https://example.test/uploads' ); }
      function wp_safe_remote_get( $u, $a ) {
        $body = 'RIFF' . pack( 'V', 200 ) . 'WEBPVP8X' . pack( 'V', 10 ) . pack( 'V', 0 ) . str_repeat( "\\0", 6 ) . 'JUNK' . pack( 'V', 160 ) . str_repeat( "\\0", 160 );
        return array( 'code' => 200, 'body' => $body );
      }
      function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
      function wp_remote_retrieve_body( $r ) { return $r['body']; }
      function wp_remote_retrieve_header( $r, $h ) { return 'image/webp'; }
      require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-image-cache.php';
      $url = Card_Vault_Image_Cache::cache_remote_image( 'https://assets.tcgdex.net/en/a/b/high.webp', 'spec-card', 1 );
      echo json_encode( array( 'url' => $url ) );
    `);
    assert.strictEqual(res.url, '', 'a failed write must not return a cache URL');
  });
});
