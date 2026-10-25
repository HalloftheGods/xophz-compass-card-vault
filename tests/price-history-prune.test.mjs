/**
 * Card Vault price history prune tests.
 * Zero mock or synthetic data: the stub wpdb only records the SQL it is asked to run.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

describe('Price history prune', () => {
  it('prune_history keeps one row per card and week instead of only Sundays', () => {
    const res = runPhpJson(`
      define('ABSPATH', '/var/www/html/');
      class Stub_Wpdb {
        public $prefix = 'wp_';
        public $queries = array();
        public function prepare( $q, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $args ); }
        public function query( $q ) { $this->queries[] = $q; return 0; }
      }
      $GLOBALS['wpdb'] = new Stub_Wpdb();
      require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-price-history.php';
      Card_Vault_Price_History::prune_history( 90 );
      echo json_encode( $GLOBALS['wpdb']->queries );
    `);
    assert.strictEqual(res.length, 1, 'one delete statement is expected');
    assert.ok(!/DAYOFWEEK/i.test(res[0]), 'a missing Sunday row must not erase the week');
    assert.ok(/MAX\(\s*recorded_date\s*\)/i.test(res[0]), 'the latest row of each card and week is kept');
  });
});
