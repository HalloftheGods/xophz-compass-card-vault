/**
 * Card Vault WC sync tests (task #6599).
 * Zero mock or synthetic data: the stubs only record which calls were made.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const PRELUDE = `
  define('ABSPATH', '/var/www/html/');
  define('WPINC', 'wp-includes');
  class WooCommerce {}
  class WP_Error {
    private $code; private $msg;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
    public function get_error_code() { return $this->code; }
  }
  class WC_Data_Exception extends Exception {}
  function is_wp_error( $v ) { return $v instanceof WP_Error; }
  function sanitize_text_field( $v ) { return trim( (string) $v ); }
  function sanitize_title( $v ) { return strtolower( (string) $v ); }
  function __( $s ) { return $s; }
  function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $key ] ?? ''; }
  function get_the_title( $id ) { return 'title'; }
  function get_posts( $args ) { $GLOBALS['posts_args'][] = $args; return array(); }
  class Card_Vault_Payout_Reversals {
    public static function order_item_sale_record_id( $o, $i ) { return "wc_order_{$o}_item_{$i}"; }
    public static function init() {}
  }
  class Card_Vault_Consignments {
    public static $result;
    public static function record_payout( $d ) { return self::$result; }
    public static function get_payout_by_sale_record_id( $id ) { return null; }
  }
  require_once '${DIR}/includes/class-card-vault-wc-sync.php';
`;

describe('Card Vault WC sync', () => {
  it('process_sales_delta reports a failed payout insert instead of counting the sale as synced', () => {
    const res = runPhpJson(`${PRELUDE}
      Card_Vault_Consignments::$result = new WP_Error( 'db_insert_payout_failed', 'insert failed' );
      $out = Card_Vault_WC_Sync::process_sales_delta( array(
        array( 'id' => 'sale-1', 'consignorId' => 'c-1', 'salePrice' => 10, 'cardName' => 'x' ),
      ) );
      echo json_encode( $out );
    `);
    assert.strictEqual(res.synced_sales, 0);
    assert.strictEqual(res.errors.length, 1);
    assert.ok(res.errors[0].includes('payout not recorded'));
    assert.ok(res.errors[0].includes('Resend this sale'));
  });

  it('process_sales_delta still counts a sale whose payout was recorded', () => {
    const res = runPhpJson(`${PRELUDE}
      Card_Vault_Consignments::$result = 7;
      $out = Card_Vault_WC_Sync::process_sales_delta( array(
        array( 'id' => 'sale-2', 'consignorId' => 'c-1', 'salePrice' => 10 ),
      ) );
      echo json_encode( $out );
    `);
    assert.strictEqual(res.synced_sales, 1);
    assert.deepStrictEqual(res.errors, []);
  });

  it('find_product_by_vault_id also looks in pending and future products', () => {
    const res = runPhpJson(`${PRELUDE}
      Card_Vault_WC_Sync::find_product_by_vault_id( 'abc' );
      echo json_encode( $GLOBALS['posts_args'][0]['post_status'] );
    `);
    assert.ok(res.includes('pending') && res.includes('future'));
  });

  it('sync_inventory_item returns a WP_Error when the SKU is already taken', () => {
    const res = runPhpJson(`${PRELUDE}
      class WC_Product_Simple {
        public function set_name( $v ) {}
        public function set_sku( $v ) { throw new WC_Data_Exception( 'Invalid or duplicated SKU.' ); }
      }
      function wc_get_product( $id ) { return null; }
      $out = Card_Vault_WC_Sync::sync_inventory_item( array( 'id' => 'v-1', 'cardId' => 'c' ) );
      echo json_encode( array( is_wp_error( $out ), is_wp_error( $out ) ? $out->get_error_code() : '' ) );
    `);
    assert.deepStrictEqual(res, [true, 'sku_conflict']);
  });

  it('auto_delist_product decrements stock with wc_update_product_stock', () => {
    const res = runPhpJson(`${PRELUDE}
      class Stub_Product {
        public function set_stock_status( $v ) {} public function set_status( $v ) {}
        public function set_catalog_visibility( $v ) {} public function add_meta_data( $k, $v, $u ) {}
        public function save() {}
        public function get_stock_quantity() { return 99; }
        public function set_stock_quantity( $v ) { $GLOBALS['direct_set'] = $v; }
      }
      $GLOBALS['calls'] = array();
      function wc_get_product( $id ) { return new Stub_Product(); }
      function wc_update_product_stock( $p, $q, $op ) { $GLOBALS['calls'][] = array( $q, $op ); return 0; }
      $out = Card_Vault_WC_Sync::auto_delist_product( 5, 2, 's1' );
      echo json_encode( array( $GLOBALS['calls'], $out['delisted'], isset( $GLOBALS['direct_set'] ) ) );
    `);
    assert.deepStrictEqual(res, [[[2, 'decrease']], true, false]);
  });
});
