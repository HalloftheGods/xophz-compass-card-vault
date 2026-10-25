/**
 * Card Vault settings and admin form handling (task #6589).
 * Zero mock or synthetic data: the stubs only record what the plugin reads and writes.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

// $GLOBALS['opts'] holds the saved options; get_option returns its default for a missing key.
const PRELUDE = `
  if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }
  if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
  class WP_Error {
    public $code; private $msg;
    public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
    public function get_error_code() { return $this->code; }
  }
  class Stub_Wpdb {
    public $prefix = 'wp_'; public $row = null; public $consignor = null; public $insert_id = 7;
    public function prepare( $sql, ...$a ) { return $sql; }
    public function get_var( $q ) { return null; }
    public function get_row( $q, $o = null ) { return $this->consignor; }
    public function insert( $t, $d, $f = null ) { $this->row = $d; return 1; }
  }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
  $GLOBALS['opts'] = array();
  function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
  function is_wp_error( $v ) { return $v instanceof WP_Error; }
  function sanitize_text_field( $v ) { return trim( (string) $v ); }
  function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
  function sanitize_email( $v ) { return trim( (string) $v ); }
  function sanitize_title( $v ) { return strtolower( (string) $v ); }
  function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
  function wp_generate_uuid4() { return 'uuid'; }
  function current_time( $t ) { return '2026-01-01 00:00:00'; }
  function __( $s ) { return $s; }
  require_once '${DIR}/includes/class-card-vault-consignments.php';
`;

const SPLIT = 'xophz_compass_card_vault_default_split_rate';

describe('Default consignor split rate setting', () => {
  it('create_consignor uses the saved setting when no rate is given', () => {
    const res = runPhpJson(`${PRELUDE}
      $GLOBALS['opts']['${SPLIT}'] = '70';
      Card_Vault_Consignments::create_consignor( array( 'name' => 'A' ) );
      $a = $GLOBALS['wpdb']->row['default_split_rate'];
      Card_Vault_Consignments::create_consignor( array( 'name' => 'B', 'default_split_rate' => '' ) );
      $b = $GLOBALS['wpdb']->row['default_split_rate'];
      Card_Vault_Consignments::create_consignor( array( 'name' => 'C', 'default_split_rate' => 60 ) );
      echo json_encode( array( $a, $b, $GLOBALS['wpdb']->row['default_split_rate'] ) );
    `);
    assert.deepStrictEqual(res, [70, 70, 60]);
  });

  it('record_payout falls back to the saved setting when neither item nor consignor has a rate', () => {
    const res = runPhpJson(`${PRELUDE}
      $GLOBALS['opts']['${SPLIT}'] = '70';
      $GLOBALS['wpdb']->consignor = array( 'consignor_id' => 'c1', 'default_split_rate' => '0.00' );
      Card_Vault_Consignments::record_payout( array( 'sale_record_id' => 's1', 'consignor_id' => 'c1', 'total_sale_amount' => 100 ) );
      echo json_encode( array( $GLOBALS['wpdb']->row['consignor_split_rate'], $GLOBALS['wpdb']->row['consignor_payout_amount'] ) );
    `);
    assert.deepStrictEqual(res, [70, 70]);
  });

  it('get_default_split_rate is 85 when unset or not a number, and bounded to 0-100', () => {
    const res = runPhpJson(`${PRELUDE}
      $out = array( Card_Vault_Consignments::get_default_split_rate() );
      $GLOBALS['opts']['${SPLIT}'] = 'abc';
      $out[] = Card_Vault_Consignments::get_default_split_rate();
      $GLOBALS['opts']['${SPLIT}'] = '150';
      $out[] = Card_Vault_Consignments::get_default_split_rate();
      $GLOBALS['opts']['${SPLIT}'] = '72.555';
      $out[] = Card_Vault_Consignments::get_default_split_rate();
      echo json_encode( $out );
    `);
    assert.deepStrictEqual(res, [85, 85, 100, 72.56]);
  });
});

describe('WooCommerce auto-sync setting', () => {
  const WC = `${PRELUDE}
    class WooCommerce {}
    function get_posts( $args ) { return array(); }
    require_once '${DIR}/includes/class-card-vault-wc-sync.php';
  `;

  it('sync_inventory_item creates no product and returns 0 when auto-sync is off', () => {
    // WC_Product_Simple is not defined here, so reaching product creation would be a fatal error.
    const res = runPhpJson(`${WC}
      $GLOBALS['opts']['xophz_compass_card_vault_auto_sync_wc'] = '0';
      $out = Card_Vault_WC_Sync::sync_inventory_item( array( 'id' => 'v-1', 'cardId' => 'c' ) );
      echo json_encode( array( $out, Card_Vault_WC_Sync::is_auto_sync_enabled() ) );
    `);
    assert.deepStrictEqual(res, [0, false]);
  });

  it('auto-sync counts as on when the setting was never saved, and off when saved empty', () => {
    const res = runPhpJson(`${WC}
      $out = array( Card_Vault_WC_Sync::is_auto_sync_enabled() );
      $GLOBALS['opts']['xophz_compass_card_vault_auto_sync_wc'] = '';
      $out[] = Card_Vault_WC_Sync::is_auto_sync_enabled();
      $GLOBALS['opts']['xophz_compass_card_vault_auto_sync_wc'] = '1';
      $out[] = Card_Vault_WC_Sync::is_auto_sync_enabled();
      echo json_encode( $out );
    `);
    assert.deepStrictEqual(res, [true, false, true]);
  });
});

describe('Card Vault admin form handling', () => {
  const ADMIN = `${PRELUDE}
    require_once '${DIR}/admin/class-card-vault-admin.php';
  `;

  it('unslashes the Register Consignor fields and leaves a blank or 0 rate to the store default', () => {
    const res = runPhpJson(`${ADMIN}
      $post = array( 'name' => "Sean O\\\\'Brien", 'notes' => "didn\\\\'t sign", 'payout_handle' => "@o\\\\'b", 'default_split_rate' => '' );
      $a = Card_Vault_Admin_Form_Input::consignor_fields( $post );
      $post['default_split_rate'] = '0';
      $b = Card_Vault_Admin_Form_Input::consignor_fields( $post );
      $post['default_split_rate'] = '72.5';
      $c = Card_Vault_Admin_Form_Input::consignor_fields( $post );
      echo json_encode( array( $a['name'], $a['notes'], $a['payout_handle'], array_key_exists( 'default_split_rate', $a ), array_key_exists( 'default_split_rate', $b ), $c['default_split_rate'] ) );
    `);
    assert.deepStrictEqual(res, ["Sean O'Brien", "didn't sign", "@o'b", false, false, 72.5]);
  });

  it('unslashes the payout payment method and reference', () => {
    const res = runPhpJson(`${ADMIN}
      $out = Card_Vault_Admin_Form_Input::payout_payment( array( 'payment_reference' => "O\\\\'Brien venmo" ) );
      echo json_encode( $out );
    `);
    assert.deepStrictEqual(res, { method: 'Cash', reference: "O'Brien venmo" });
  });

  it('keeps the saved game selection when the form listed no games or none is ticked', () => {
    const res = runPhpJson(`${ADMIN}
      echo json_encode( array(
        Card_Vault_Admin_Form_Input::catalog_categories( array( 'sets_scope' => '10' ) ),
        Card_Vault_Admin_Form_Input::catalog_categories( array( 'categories_rendered' => '1' ) ),
        Card_Vault_Admin_Form_Input::catalog_categories( array( 'categories_rendered' => '1', 'categories' => array( '71', '3', '3' ) ) ),
      ) );
    `);
    assert.deepStrictEqual(res, [
      { categories: null, msg: 'catalog_saved_categories_kept' },
      { categories: null, msg: 'catalog_no_category' },
      { categories: [71, 3], msg: 'catalog_saved' },
    ]);
  });

  it('sanitizes the split rate and auto-sync settings on save', () => {
    const res = runPhpJson(`${ADMIN}
      $GLOBALS['opts']['${SPLIT}'] = '75';
      echo json_encode( array(
        Card_Vault_Admin_Form_Input::sanitize_split_rate( '150' ),
        Card_Vault_Admin_Form_Input::sanitize_split_rate( '66.666' ),
        Card_Vault_Admin_Form_Input::sanitize_split_rate( 'abc' ),
        Card_Vault_Admin_Form_Input::sanitize_checkbox( null ),
        Card_Vault_Admin_Form_Input::sanitize_checkbox( '1' ),
      ) );
    `);
    assert.deepStrictEqual(res, [100, 66.67, '75', '0', '1']);
  });
});

describe('Dealer HQ Register Consignor dialog', () => {
  it('sends a blank split rate so the server applies the store default', async () => {
    // Other specs set window at import time and read it later, so restore it, never delete it.
    const realWindow = globalThis.window;
    globalThis.window = realWindow ?? { Compass: { Vue: { ref: (value) => ({ value }) } } };
    const sent = [];
    const realFetch = globalThis.fetch;
    globalThis.fetch = async (url, init) => {
      sent.push(JSON.parse(init.body));
      return { ok: true, status: 201, json: async () => ({ success: true }) };
    };
    try {
      const { useConsignorForm } = await import('../admin/js/card-vault-admin/use-consignor-form.js');
      const form = useConsignorForm({ context: { restUrl: '/wp-json/', nonce: 'n' } }, () => {}, async () => {});
      assert.strictEqual(form.newConsignor.value.default_split_rate, null);
      form.newConsignor.value.name = 'A';
      await form.createConsignor();
      assert.strictEqual(sent[0].default_split_rate, null);
    } finally {
      globalThis.fetch = realFetch;
      globalThis.window = realWindow;
    }
  });
});
