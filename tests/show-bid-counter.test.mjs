/**
 * Card Vault show-bid counter validation tests.
 * Zero mock or synthetic data: the stub wpdb only records whether a row was written.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const runCounter = (amount, updateReturn = '1') => runPhpJson(`
  define('ABSPATH', '/var/www/html/');
  class WP_Error { public $code; public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; } }
  function __( $s ) { return $s; }
  class Stub_Wpdb {
    public $prefix = 'wp_';
    public $updates = array();
    public $last_error = '';
    public function prepare( $q ) { return $q; }
    public function get_row() { return array( 'id' => 1, 'collector_user_id' => 2 ); }
    public function update( $t, $d ) { $this->updates[] = $d; return ${updateReturn}; }
  }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
  require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-community.php';
  $r = Card_Vault_Community::action_show_bid( 1, 2, 'countered', ${amount} );
  echo json_encode( array( 'error' => $r instanceof WP_Error ? $r->code : null, 'result' => $r, 'updates' => $GLOBALS['wpdb']->updates ) );
`);

describe('Show bid counter amount', () => {
  for (const bad of ['0', '-50', 'NAN']) {
    it(`rejects ${bad} without writing`, () => {
      const res = runCounter(bad);
      assert.strictEqual(res.error, 'invalid_counter_amount');
      assert.strictEqual(res.updates.length, 0);
    });
  }
  it('reports a database error as false, and an unchanged row as true', () => {
    assert.strictEqual(runCounter('100', 'false').result, false);
    assert.strictEqual(runCounter('100', '0').result, true);
  });
  it('stores a positive amount rounded to cents', () => {
    const res = runCounter('115.456');
    assert.strictEqual(res.error, null);
    assert.strictEqual(res.updates[0].counter_amount, 115.46);
  });
});
