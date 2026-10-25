/**
 * Public showcase topBids privacy tests.
 * Zero mock or synthetic data: the stub wpdb returns a minimal row shape
 * only to prove the bids query result is withheld or released by the stored choice.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const runShowcase = (metaValue) => runPhpJson(`
  function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
  function sanitize_title( $s ) { return $s; }
  function sanitize_user( $s ) { return $s; }
  function get_user_by( $f, $v ) { return (object) array( 'ID' => 7, 'display_name' => 'x' ); }
  function get_user_meta( $id, $key, $single = false ) { return ${metaValue}; }
  define('ARRAY_A', 'ARRAY_A');
  class Stub_Wpdb {
    public $prefix = 'wp_';
    public function prepare( $q ) { return $q; }
    public function get_var() { return '1'; }
    public function get_results( $q ) {
      if ( false !== strpos( $q, 'show_bids' ) ) {
        return array( array( 'vendor_name' => 'v', 'offer_cash_amount' => '5' ) );
      }
      return array();
    }
  }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
  require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-community.php';
  $r = Card_Vault_Community::get_public_showcase( 'x' );
  echo json_encode( array( 'topBids' => $r['topBids'] ?? null ) );
`);

describe('Public showcase topBids', () => {
  it('withholds vendor offers by default', () => {
    assert.deepStrictEqual(runShowcase("''").topBids, []);
  });
  it('withholds vendor offers when the choice is private', () => {
    assert.deepStrictEqual(runShowcase("'private'").topBids, []);
  });
  it('returns offers only when the collector chose ticker', () => {
    assert.strictEqual(runShowcase("'ticker'").topBids.length, 1);
  });
});
