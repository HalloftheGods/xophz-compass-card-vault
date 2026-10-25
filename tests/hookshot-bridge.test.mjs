/**
 * Card_Vault_Hookshot_Bridge download_url specs.
 * Runs the real class under /usr/bin/php with stubbed WordPress HTTP functions that record calls.
 * Strictly zero em dashes in code, copy, comments, and reports.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert';

const PLUGIN_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function run(url) {
  const code = `
define('ABSPATH', '/tmp/');
$GLOBALS['calls'] = array();
function esc_url_raw($u) { return $u; }
function wp_tempnam($p) { return tempnam(sys_get_temp_dir(), 'cvb'); }
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_get($u, $a = array()) { $GLOBALS['calls'][] = 'unsafe:' . $u; return array(); }
function wp_safe_remote_get($u, $a = array()) { $GLOBALS['calls'][] = 'safe:' . $u . ':' . $a['redirection'] . ':' . $a['limit_response_size']; return array(); }
function do_action() {}
class Card_Vault_Catalog_Importer {
  public static function ingest_pricecharting_csv($f) { $GLOBALS['calls'][] = 'ingest'; return array('success' => true); }
}
require '${PLUGIN_ROOT}/includes/class-card-vault-hookshot-bridge.php';
Card_Vault_Hookshot_Bridge::process_async_sync_job(array('source' => 'pricecharting', 'download_url' => ${JSON.stringify(url)}));
echo json_encode($GLOBALS['calls']);
`;
  return JSON.parse(execFileSync('/usr/bin/php', ['-r', code], { encoding: 'utf8' }));
}

describe('Card_Vault_Hookshot_Bridge pricecharting download_url', () => {
  it('refuses an internal host without making a request', () => {
    assert.deepStrictEqual(run('http://169.254.169.254/latest/meta-data'), []);
  });

  it('refuses a non-allowlisted https host', () => {
    assert.deepStrictEqual(run('https://evil.example.com/prices.csv'), []);
  });

  it('refuses a lookalike host with user info', () => {
    assert.deepStrictEqual(run('https://www.pricecharting.com@evil.example.com/p.csv'), []);
  });

  it('fetches an allowlisted URL with wp_safe_remote_get, no redirects and a size cap', () => {
    const calls = run('https://www.pricecharting.com/price-guide/download-custom?t=x');
    assert.strictEqual(calls.length, 2);
    assert.ok(calls[0].startsWith('safe:https://www.pricecharting.com/'));
    assert.ok(calls[0].endsWith(':0:268435456'));
    assert.strictEqual(calls[1], 'ingest');
  });
});
