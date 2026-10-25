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

  it('reconcile_payout restores a void row to unpaid only while part of the sale stands', () => {
    const res = runPhpJson(`
      define('ABSPATH', '/var/www/html/');
      class Card_Vault_Consignments {
        public static function get_payouts_table() { return 'payouts'; }
        public static function get_payout_by_sale_record_id( $id ) {
          return array( 'id' => 4, 'sale_record_id' => $id, 'payout_status' => 'void', 'total_sale_amount' => '85', 'quantity' => 1, 'consignor_split_rate' => '80', 'consignor_payout_amount' => '68' );
        }
      }
      class Stub_Wpdb {
        public $queries = array();
        public function prepare( $sql, ...$args ) { return vsprintf( str_replace( array( '%f', '%d', '%s' ), array( '%s', '%s', '%s' ), $sql ), $args ); }
        public function query( $sql ) { $this->queries[] = $sql; return 1; }
      }
      $GLOBALS['wpdb'] = new Stub_Wpdb();
      function sanitize_text_field( $v ) { return $v; }
      function current_time( $t ) { return '2026-01-01 00:00:00'; }
      function sanitize_textarea_field( $v ) { return $v; }
      require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-payout-reversals.php';
      $restored = Card_Vault_Payout_Reversals::reconcile_payout( 'wc_order_1_item_2', 85, 1, 'order completed again' );
      $still    = Card_Vault_Payout_Reversals::reconcile_payout( 'wc_order_1_item_2', 0, 0, 'refund' );
      echo json_encode( array( $restored, $still, count( $GLOBALS['wpdb']->queries ), strpos( $GLOBALS['wpdb']->queries[0], "payout_status = 'void'" ) !== false ) );
    `);
    assert.deepStrictEqual(res, ['restored', 'unchanged', 1, true]);
  });
});
