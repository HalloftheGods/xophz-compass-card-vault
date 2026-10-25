/**
 * Card_Vault_Catalog_DB search, count and upsert specs.
 * Runs the real class under /usr/bin/php against a recording $wpdb stand-in.
 * The stand-in only records SQL and returns counts chosen by each test; it holds no card data.
 * Strictly zero em dashes in code, copy, comments, and reports.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert';

const PLUGIN_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// rules: substring => count. The first rule whose substring is in a COUNT(*) query decides.
const PRELUDE = (rules, queryResult) => `<?php
define('ABSPATH', '/tmp/');
define('ARRAY_A', 'ARRAY_A');
function sanitize_text_field($v) { return trim((string) $v); }
require_once '${PLUGIN_ROOT}/includes/class-card-vault-number-normalizer.php';
require_once '${PLUGIN_ROOT}/includes/class-card-vault-catalog-db.php';
class Fake_Wpdb {
  public $prefix = 'wp_';
  public $last_error = 'fake failure';
  public $log = array();
  public $rules = array();
  public function esc_like($s) { return addcslashes($s, '_%\\\\'); }
  public function prepare($q, ...$args) {
    if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
    $i = 0;
    return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
      $v = $args[$i++];
      return $m[0] === '%s' ? "'" . $v . "'" : (string) $v;
    }, $q);
  }
  public function get_var($sql) {
    $this->log[] = $sql;
    foreach ($this->rules as $needle => $n) { if (strpos($sql, $needle) !== false) { return $n; } }
    return 0;
  }
  public function get_results($sql, $mode = null) { $this->log[] = $sql; return array(); }
  public function query($sql) { $this->log[] = $sql; return ${queryResult}; }
}
$rules_json = '${JSON.stringify(rules).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}';
`;

function run(rules, queryResult, body) {
  const code = PRELUDE(rules, queryResult) + `
global $wpdb; $wpdb = new Fake_Wpdb(); $wpdb->rules = json_decode($rules_json, true);
${body}
echo json_encode(array('log' => $wpdb->log, 'out' => $out));
`;
  const raw = execFileSync('/usr/bin/php', ['-d', 'display_errors=stderr'], { input: code, encoding: 'utf8', stdio: ['pipe', 'pipe', 'ignore'] });
  return JSON.parse(raw);
}

const lastSelect = (log) => log.filter((s) => s.startsWith('SELECT *')).pop();

describe('Catalog DB search and upsert', () => {
  it('Fast Path D wraps its OR so the category filter applies to both branches', () => {
    const { log } = run({ "clean_number = '4'": 3 }, 1, `$out = Card_Vault_Catalog_DB::search_cards('4', array('category_id' => 3), 24, 0);`);
    assert.ok(lastSelect(log).includes("(clean_number = '4' OR raw_number = '4') AND category_id = 3"), lastSelect(log));
  });

  it('Fast Path C applies the group and rarity filters', () => {
    const { log } = run({ 'numeric_number': 4 }, 1, `$out = Card_Vault_Catalog_DB::search_cards('Charizard 4', array('group_id' => 7, 'rarity' => 'Rare'), 24, 0);`);
    const sql = lastSelect(log);
    assert.ok(sql.includes('numeric_number') && sql.includes('AND group_id = 7') && sql.includes("AND rarity = 'Rare'"), sql);
  });

  it('applies a group_ids list as an IN clause', () => {
    const { log } = run({ 'MATCH': 2 }, 1, `$out = Card_Vault_Catalog_DB::search_cards('Charizard', array('group_ids' => array(1, 2)), 24, 0);`);
    assert.ok(lastSelect(log).includes('group_id IN (1, 2)'), lastSelect(log));
  });

  it('the LIKE fallback keeps the rarity filter', () => {
    const { log } = run({ 'LIKE': 2 }, 1, `$out = Card_Vault_Catalog_DB::search_cards('zzz', array('rarity' => 'Secret Rare'), 24, 0);`);
    const sql = lastSelect(log);
    assert.ok(sql.includes('LIKE') && sql.includes("AND rarity = 'Secret Rare'"), sql);
  });

  it('count_cards counts a TCGPlayer id search even when the name search finds nothing', () => {
    const { out } = run({ 'tcgplayer_id = 106996': 1 }, 1, `$out = Card_Vault_Catalog_DB::count_cards('106996');`);
    assert.strictEqual(out, 1);
  });

  it('a later page keeps the number path and does not fall through to the name search', () => {
    const { log, out } = run({ 'numeric_number': 30, 'MATCH': 150 }, 1, `Card_Vault_Catalog_DB::search_cards('Charizard 4', array(), 24, 24); $out = Card_Vault_Catalog_DB::count_cards('Charizard 4');`);
    assert.strictEqual(out, 30);
    assert.ok(lastSelect(log).includes('numeric_number') && lastSelect(log).includes('OFFSET 24'), lastSelect(log));
  });

  it('batch_upsert_cards does not count a chunk whose query fails', () => {
    const { out, log } = run({}, 'false', `$out = Card_Vault_Catalog_DB::batch_upsert_cards(array(array('id' => 'a', 'name' => 'A')));`);
    assert.strictEqual(log.length, 1);
    assert.strictEqual(out, 0);
  });

  it('batch_upsert_cards counts a chunk whose query succeeds', () => {
    const { out } = run({}, '1', `$out = Card_Vault_Catalog_DB::batch_upsert_cards(array(array('id' => 'a', 'name' => 'A')));`);
    assert.strictEqual(out, 1);
  });
});
