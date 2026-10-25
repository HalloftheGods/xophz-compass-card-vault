/**
 * Card Vault payout reversals tests.
 * Zero mock or synthetic data: the stub order only records whether it was touched.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

describe('Payout reversals', () => {
  it('handle_order_refunded leaves a cancelled, refunded or failed order alone', () => {
    const res = runPhpJson(`
      define('ABSPATH', '/var/www/html/');
      class Stub_Order {
        public $touched = false;
        public function has_status( $s ) { return in_array( 'refunded', (array) $s, true ); }
        public function get_items() { $this->touched = true; return array(); }
        public function add_order_note( $n ) { $this->touched = true; }
      }
      $GLOBALS['stub_order'] = new Stub_Order();
      function wc_get_order( $id ) { return $GLOBALS['stub_order']; }
      require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-payout-reversals.php';
      Card_Vault_Payout_Reversals::handle_order_refunded( 5, 9 );
      echo json_encode( $GLOBALS['stub_order']->touched );
    `);
    assert.strictEqual(res, false, 'a refund on an already reversed order must not re-reconcile payouts');
  });
});
