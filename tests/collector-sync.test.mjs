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

const STUB_WPDB = `
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
    public function query( $q ) { $this->calls[] = array( 'query', $q ); return 0; }
  }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
  require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-community.php';
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
