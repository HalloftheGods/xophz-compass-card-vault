/**
 * Card Vault schema upgrade path (task #6591).
 * The stubs only record which tables dbDelta saw and which options were written.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const PRELUDE = `
  if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }
  class Stub_Wpdb { public $prefix = 'wp_'; public function get_charset_collate() { return ''; } }
  $GLOBALS['wpdb'] = new Stub_Wpdb();
  $GLOBALS['opts'] = array(); $GLOBALS['tables'] = array(); $GLOBALS['blogs'] = array();
  function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
  function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
  function dbDelta( $sql ) { preg_match( '/CREATE TABLE (\\\\S+)/', $sql, $m ); $GLOBALS['tables'][] = $m[1]; }
  function add_role() {} function get_role() { return null; } function __( $s ) { return $s; }
  function flush_rewrite_rules() {}
  function is_multisite() { return true; }
  function get_sites() { return array( 2, 3 ); }
  function switch_to_blog( $id ) { $GLOBALS['blogs'][] = $id; }
  function restore_current_blog() {}
  require_once '${DIR}/includes/class-card-vault-activator.php';
`;

describe('Card Vault schema upgrade', () => {
  it('maybe_upgrade creates tables when the stored version is missing, then stays quiet', () => {
    const res = runPhpJson(`${PRELUDE}
      Card_Vault_Activator::maybe_upgrade();
      $first = count( $GLOBALS['tables'] );
      Card_Vault_Activator::maybe_upgrade();
      echo json_encode( array( $first > 0, count( $GLOBALS['tables'] ) === $first, in_array( 'wp_xophz_vault_show_bids', $GLOBALS['tables'], true ) ) );
    `);
    assert.deepStrictEqual(res, [true, true, true]);
  });

  it('network activation installs the schema on every subsite', () => {
    const res = runPhpJson(`${PRELUDE}
      Card_Vault_Activator::activate( true );
      echo json_encode( $GLOBALS['blogs'] );
    `);
    assert.deepStrictEqual(res, [2, 3]);
  });
});
