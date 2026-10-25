/**
 * Card Vault collector collection sync tests.
 * Zero mock or synthetic data: the stub wpdb only records the calls it is asked to
 * make and returns the rows a test hands it.
 * Zero em dashes: hyphens or colons only.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const STUB_WPDB_BASE = `
  if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
  if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) $s ); } }
  if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return 'generated-uuid'; } }
  if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( (string) $s ); } }
  if ( ! function_exists( 'sanitize_user' ) ) { function sanitize_user( $s ) { return (string) $s; } }
  if ( ! function_exists( 'get_user_by' ) ) { function get_user_by( $f, $v ) { return (object) array( 'ID' => 7, 'display_name' => 'Collector' ); } }
  class Stub_Wpdb {
    public $prefix = 'wp_';
    public $calls = array();
    public $existing_ids = array();
    public $rows = array();
    public function prepare( $q, ...$args ) { return $q; }
    public function get_col( $q ) { return $this->existing_ids; }
    public function get_var( $q ) { return 1; }
    public function get_results( $q, $o = null ) { return strpos( $q, 'show_bids' ) !== false ? array() : $this->rows; }
    public function replace( $t, $d, $f = null ) { $this->calls[] = array( 'replace', $d ); return 1; }
    public function insert( $t, $d, $f = null ) { $this->calls[] = array( 'insert', $d ); return 1; }
    public function update( $t, $d, $w, $f = null, $wf = null ) { $this->calls[] = array( 'update', $d, $w ); return 1; }
    public $query_result = 0;
    public $batch = null;
    public $insert_id = 5;
    public function get_row( $q, $o = null ) { return $this->batch; }
    public function query( $q ) { $this->calls[] = array( 'query', $q ); return $this->query_result; }
  }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
`;
const LOAD_CLASS = `  require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-community.php';
`;
const STUB_WPDB = STUB_WPDB_BASE + LOAD_CLASS;
// The harness fixes get_option, current_user_can and the transient functions to constants, so the
// guard tests load the class with those calls renamed to the fakes below, which read $GLOBALS.
const STUB_WPDB_FAKES = STUB_WPDB_BASE + `
  $GLOBALS['opts'] = array();
  $GLOBALS['caps'] = array();
  $GLOBALS['transients'] = array();
  if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
  if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password() { return 'abcdef'; } }
  function fake_get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
  function fake_update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
  function fake_current_user_can( $c ) { return ! empty( $GLOBALS['caps'][ $c ] ); }
  function fake_get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
  function fake_set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
  $source = file_get_contents( '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-community.php' );
  foreach ( array( 'get_option', 'update_option', 'current_user_can', 'get_transient', 'set_transient' ) as $fn ) {
    $source = preg_replace( '/(?<![A-Za-z0-9_>:])' . $fn . '\\\\(/', 'fake_' . $fn . '(', $source );
  }
  eval( '?>' . $source );
`;

const CLIENT_ITEM = `array(
  'id'                  => 'item-1',
  'cardId'              => 'sv4-25',
  'card'                => array( 'id' => 'sv4-25', 'name' => 'Pikachu', 'setName' => '151', 'number' => '025/165' ),
  'condition'           => 'NM',
  'quantity'            => 1,
  'dateAdded'           => '2026-10-01',
  'lastModified'        => '2026-10-20T14:05:00.000Z',
  'consignorId'         => 'consignor-9',
  'consignorName'       => 'Consignor Nine',
  'consignorSplitRate'  => 70,
  'customValuation'     => 42.5,
  'certificationStatus' => 'certified',
  'aiGradeReport'       => array( 'certNumber' => 'CV-1', 'predictedGrade' => 9 ),
)`;

describe('Collector sync and intake guards (audit r4)', () => {
  it('an empty push does not delete the collection unless replaceAll is set', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      $GLOBALS['wpdb']->existing_ids = array( 'item-1', 'item-2' );
      $refused = Card_Vault_Community::sync_collector_items( 7, array() );
      $after_refused = count( array_filter( $GLOBALS['wpdb']->calls, function ( $c ) { return 'query' === $c[0]; } ) );
      $allowed = Card_Vault_Community::sync_collector_items( 7, array(), true );
      $after_allowed = count( array_filter( $GLOBALS['wpdb']->calls, function ( $c ) { return 'query' === $c[0]; } ) );
      echo json_encode( array( $refused, $after_refused, $allowed, $after_allowed ) );
    `);
    assert.deepStrictEqual(res, [false, 0, true, 1]);
  });

  it('a new row without isPublicShowcase is private, and an existing row keeps its flag', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      $GLOBALS['wpdb']->existing_ids = array( 'item-1' );
      $known = ${CLIENT_ITEM};
      $new_item = $known;
      $new_item['id'] = 'item-2';
      Card_Vault_Community::sync_collector_items( 7, array( $known, $new_item ) );
      $calls = $GLOBALS['wpdb']->calls;
      echo json_encode( array( array_key_exists( 'is_public_showcase', $calls[0][1] ), $calls[1][1]['is_public_showcase'] ) );
    `);
    assert.deepStrictEqual(res, [false, 0]);
  });

  it('an explicit isPublicShowcase flag is stored for both new and known rows', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      $GLOBALS['wpdb']->existing_ids = array( 'item-1' );
      $known = ${CLIENT_ITEM};
      $known['isPublicShowcase'] = true;
      $new_item = $known;
      $new_item['id'] = 'item-2';
      Card_Vault_Community::sync_collector_items( 7, array( $known, $new_item ) );
      $calls = $GLOBALS['wpdb']->calls;
      echo json_encode( array( $calls[0][1]['is_public_showcase'], $calls[1][1]['is_public_showcase'] ) );
    `);
    assert.deepStrictEqual(res, [1, 1]);
  });

  it('a push over the item cap is refused and writes nothing', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      $items = array();
      for ( $i = 0; $i < 5001; $i++ ) { $items[] = array( 'id' => 'i' . $i ); }
      $out = Card_Vault_Community::sync_collector_items( 7, $items );
      echo json_encode( array( $out, count( $GLOBALS['wpdb']->calls ) ) );
    `);
    assert.deepStrictEqual(res, [false, 0]);
  });

  it('the public showcase and show bids are refused when public showcases are disabled', () => {
    const res = runPhpJson(`
      ${STUB_WPDB_FAKES}
      fake_update_option( Card_Vault_Community::SETTINGS_OPTION_KEY, array( 'enable_public_showcases' => false ) );
      $show = Card_Vault_Community::get_public_showcase( 'collector' );
      $bid  = Card_Vault_Community::submit_show_bid( array( 'showcaseSlug' => 'collector', 'vendorName' => 'Vendor' ) );
      echo json_encode( array( is_wp_error( $show ) ? $show->get_error_data()['status'] : 'ok', is_wp_error( $bid ) ? $bid->get_error_data()['status'] : 'ok' ) );
    `);
    assert.deepStrictEqual(res, [403, 403]);
  });

  it('a non-dealer intake submit is refused when community vaults are off, a dealer is not', () => {
    const res = runPhpJson(`
      ${STUB_WPDB_FAKES}
      fake_update_option( Card_Vault_Community::SETTINGS_OPTION_KEY, array( 'allow_community_vaults' => false ) );
      $payload = array( 'items' => array( array( 'cardId' => 'sv4-25' ) ) );
      $guest  = Card_Vault_Community::submit_intake_batch( 7, $payload );
      $GLOBALS['caps']['manage_woocommerce'] = true;
      $dealer = Card_Vault_Community::submit_intake_batch( 7, $payload );
      echo json_encode( array( is_wp_error( $guest ) ? $guest->get_error_data()['status'] : 'ok', is_wp_error( $dealer ) ? 'error' : 'ok' ) );
    `);
    assert.deepStrictEqual(res, [403, 'ok']);
  });

  it('an intake batch over the line cap, or a submitter over the hourly throttle, is refused', () => {
    const res = runPhpJson(`
      ${STUB_WPDB_FAKES}
      fake_update_option( Card_Vault_Community::SETTINGS_OPTION_KEY, array( 'allow_community_vaults' => true ) );
      $lines = array();
      for ( $i = 0; $i < 501; $i++ ) { $lines[] = array( 'cardId' => 'c' . $i ); }
      $big = Card_Vault_Community::submit_intake_batch( 7, array( 'items' => $lines ) );
      $statuses = array( is_wp_error( $big ) ? $big->get_error_data()['status'] : 'ok' );
      for ( $i = 0; $i < 21; $i++ ) {
        $r = Card_Vault_Community::submit_intake_batch( 7, array( 'items' => array( array( 'cardId' => 'c' ) ) ) );
        $last = is_wp_error( $r ) ? $r->get_error_data()['status'] : 'ok';
      }
      $statuses[] = $last;
      echo json_encode( $statuses );
    `);
    assert.deepStrictEqual(res, [400, 429]);
  });

  it('accept_intake_batch refuses an already accepted or accepting batch and claims the batch atomically', () => {
    const res = runPhpJson(`
      ${STUB_WPDB_FAKES}
      $GLOBALS['wpdb']->batch = array( 'id' => 3, 'status' => 'accepted', 'consignor_id' => 'c', 'agreed_split_rate' => 80 );
      $GLOBALS['wpdb']->query_result = 0;
      $again = Card_Vault_Community::accept_intake_batch( 3 );
      $lost_race = ( function () {
        $GLOBALS['wpdb']->batch = array( 'id' => 3, 'status' => 'appraised', 'consignor_id' => 'c', 'agreed_split_rate' => 80 );
        return Card_Vault_Community::accept_intake_batch( 3 );
      } )();
      $GLOBALS['wpdb']->query_result = 1;
      $won = Card_Vault_Community::accept_intake_batch( 3 );
      echo json_encode( array(
        is_wp_error( $again ) ? $again->get_error_data()['status'] : 'ok',
        is_wp_error( $lost_race ) ? $lost_race->get_error_data()['status'] : 'ok',
        is_wp_error( $won ) ? 'error' : $won['status'],
      ) );
    `);
    assert.deepStrictEqual(res, [409, 409, 'accepted']);
  });

  it('update_settings changes users_can_register only for a user who can manage_options', () => {
    const res = runPhpJson(`
      ${STUB_WPDB_FAKES}
      $GLOBALS['opts']['users_can_register'] = 0;
      $GLOBALS['caps']['manage_woocommerce'] = true;
      $shop = Card_Vault_Community::update_settings( array( 'allow_community_vaults' => true ) );
      $after_shop = $GLOBALS['opts']['users_can_register'];
      $GLOBALS['caps']['manage_options'] = true;
      Card_Vault_Community::update_settings( array( 'allow_community_vaults' => true ) );
      echo json_encode( array( $after_shop, $GLOBALS['opts']['users_can_register'], $shop['allow_community_vaults'] ) );
    `);
    assert.deepStrictEqual(res, [0, 1, true]);
  });
});

describe('Collector collection sync', () => {
  it('sync_collector_items updates a known row in place and inserts only new ids, never REPLACE', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      $GLOBALS['wpdb']->existing_ids = array( 'item-1' );
      $known = ${CLIENT_ITEM};
      $new_item = $known;
      $new_item['id'] = 'item-2';
      Card_Vault_Community::sync_collector_items( 7, array( $known, $new_item, $new_item ) );
      echo json_encode( array_map( function ( $c ) { return $c[0]; }, $GLOBALS['wpdb']->calls ) );
    `);
    assert.deepStrictEqual(res, ['update', 'insert', 'update'], 'REPLACE resets created_at and updated_at on every push');
  });

  it('sync_collector_items stores the client dates and the consignor, grade and valuation fields', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      Card_Vault_Community::sync_collector_items( 7, array( ${CLIENT_ITEM} ) );
      $call = $GLOBALS['wpdb']->calls[0];
      $payload = json_decode( $call[1]['card_payload'], true );
      echo json_encode( $payload['_cardVaultItem'] ?? null );
    `);
    assert.strictEqual(res.dateAdded, '2026-10-01');
    assert.strictEqual(res.lastModified, '2026-10-20T14:05:00.000Z');
    assert.strictEqual(res.consignorId, 'consignor-9');
    assert.strictEqual(res.consignorName, 'Consignor Nine');
    assert.strictEqual(res.consignorSplitRate, 70);
    assert.strictEqual(res.customValuation, 42.5);
    assert.strictEqual(res.certificationStatus, 'certified');
    assert.strictEqual(res.aiGradeReport.certNumber, 'CV-1');
  });

  it('get_collector_items returns the stored fields, and an empty lastModified for a row without them', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      Card_Vault_Community::sync_collector_items( 7, array( ${CLIENT_ITEM} ) );
      $stored = $GLOBALS['wpdb']->calls[0][1];
      $row = array_merge( $stored, array( 'item_id' => 'item-1', 'created_at' => '2026-10-24 09:00:00', 'updated_at' => '2026-10-24 09:00:00' ) );
      $legacy = array_merge( $row, array( 'item_id' => 'item-0', 'card_payload' => json_encode( array( 'id' => 'sv4-25', 'name' => 'Pikachu' ) ) ) );
      $GLOBALS['wpdb']->rows = array( $row, $legacy );
      echo json_encode( Card_Vault_Community::get_collector_items( 7 ) );
    `);
    const [stored, legacy] = res;
    assert.strictEqual(stored.dateAdded, '2026-10-01', 'dateAdded is the client acquisition date, not the push day');
    assert.strictEqual(stored.lastModified, '2026-10-20T14:05:00.000Z', 'lastModified is the client edit time, not the push time');
    assert.strictEqual(stored.consignorId, 'consignor-9');
    assert.strictEqual(stored.consignorSplitRate, 70);
    assert.strictEqual(stored.aiGradeReport.certNumber, 'CV-1');
    assert.ok(!('_cardVaultItem' in stored.card), 'the item fields are not returned inside the card');
    assert.strictEqual(legacy.lastModified, '', 'a row without a stored client time reports no lastModified');
    assert.strictEqual(legacy.dateAdded, '2026-10-24');
    assert.ok(!('consignorId' in legacy), 'a row without stored item fields leaves them out');
  });

  it('get_collector_items sends a cleared consignor as null so the merge clears it on other devices', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      $item = ${CLIENT_ITEM};
      unset( $item['consignorId'], $item['consignorName'], $item['consignorSplitRate'] );
      Card_Vault_Community::sync_collector_items( 7, array( $item ) );
      $row = array_merge( $GLOBALS['wpdb']->calls[0][1], array( 'item_id' => 'item-1', 'created_at' => '2026-10-24 09:00:00', 'updated_at' => '2026-10-24 09:00:00' ) );
      $GLOBALS['wpdb']->rows = array( $row );
      echo json_encode( Card_Vault_Community::get_collector_items( 7 )[0] );
    `);
    assert.ok('consignorId' in res, 'the key is present');
    assert.strictEqual(res.consignorId, null);
    assert.strictEqual(res.consignorSplitRate, null);
  });

  it('get_public_showcase does not expose consignor, grade or valuation fields', () => {
    const res = runPhpJson(`
      ${STUB_WPDB}
      Card_Vault_Community::sync_collector_items( 7, array( ${CLIENT_ITEM} ) );
      $row = array_merge( $GLOBALS['wpdb']->calls[0][1], array( 'item_id' => 'item-1', 'created_at' => '2026-10-24 09:00:00', 'updated_at' => '2026-10-24 09:00:00' ) );
      $GLOBALS['wpdb']->rows = array( $row );
      echo json_encode( Card_Vault_Community::get_public_showcase( 'collector' ) );
    `);
    const serialized = JSON.stringify(res.items);
    assert.strictEqual(res.items.length, 1);
    assert.ok(!serialized.includes('consignor-9'), 'consignor ids stay private');
    assert.ok(!serialized.includes('_cardVaultItem'), 'the stored item fields stay private');
  });
});
