/**
 * Card Vault consignments: split rate bounds and rounding in record_payout.
 * Zero mock data: the stub wpdb only records what record_payout inserts.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const run = (payout, consignor) => runPhpJson(`
  if ( ! defined('ARRAY_A') ) { define('ARRAY_A', 'ARRAY_A'); }
  class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } }
  class Stub_Wpdb {
    public $prefix = 'wp_'; public $row = null; public $insert_id = 7;
    public function prepare( $sql, ...$a ) { return $sql; }
    public function get_var( $q ) { return null; }
    public function get_row( $q, $o = null ) { return ${consignor}; }
    public function insert( $t, $d, $f = null ) { $this->row = $d; return 1; }
  }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
  function sanitize_text_field( $v ) { return $v; }
  function current_time( $t ) { return '2026-01-01 00:00:00'; }
  function __( $s ) { return $s; }
  function is_wp_error( $x ) { return $x instanceof WP_Error; }
  require_once '${DIR}/includes/class-card-vault-consignments.php';
  $r = Card_Vault_Consignments::record_payout( ${payout} );
  echo json_encode( is_wp_error( $r ) ? array( 'error' => $r->code ) : array( 'rate' => $GLOBALS['wpdb']->row['consignor_split_rate'], 'payout' => $GLOBALS['wpdb']->row['consignor_payout_amount'], 'profit' => $GLOBALS['wpdb']->row['dealer_profit_amount'] ) );
`);

const base = "'sale_record_id' => 's1', 'consignor_id' => 'c1', 'total_sale_amount' => 100";
const consignor = "array( 'consignor_id' => 'c1', 'default_split_rate' => '85.00', 'payout_method' => 'Cash' )";

describe('Consignments record_payout bounds', () => {
  it('clamps a rate above 100', () => {
    const res = run(`array( ${base}, 'consignor_split_rate' => 850 )`, consignor);
    assert.deepStrictEqual(res, { rate: 100, payout: 100, profit: 0 });
  });
  it('rounds the rate to the stored 2 decimals', () => {
    const res = run(`array( 'sale_record_id' => 's1', 'consignor_id' => 'c1', 'total_sale_amount' => 900, 'consignor_split_rate' => 66.664 )`, consignor);
    assert.strictEqual(res.rate, 66.66);
    assert.strictEqual(res.payout, 599.94);
  });
  it('rejects a negative sale amount and an unknown consignor', () => {
    assert.deepStrictEqual(run(`array( 'sale_record_id' => 's1', 'consignor_id' => 'c1', 'total_sale_amount' => -5 )`, consignor), { error: 'invalid_sale_amount' });
    assert.deepStrictEqual(run(`array( ${base} )`, 'null'), { error: 'unknown_consignor' });
  });
});
