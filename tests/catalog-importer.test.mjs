/**
 * Card_Vault_Catalog_Importer PriceCharting ingest specs.
 * Runs the real class under /usr/bin/php. The $wpdb stand-in records SQL and returns one
 * catalog candidate row; the CSV text under test is the only input data.
 * Strictly zero em dashes in code, copy, comments, and reports.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import assert from 'node:assert';

const PLUGIN_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function ingest(csv) {
  const dir = mkdtempSync(path.join(tmpdir(), 'cv-importer-'));
  const file = path.join(dir, 'pc.csv');
  writeFileSync(file, csv);
  const code = `<?php
define('ABSPATH', '/tmp/');
define('ARRAY_A', 'ARRAY_A');
class Card_Vault_Catalog_DB {
  public static function ensure_database() {}
  public static function get_table_name() { return 'cards'; }
}
class Card_Vault_Price_History {
  public static $rows = array();
  public static function record_batch_snapshots($rows) { self::$rows = $rows; return count($rows); }
}
class Fake_Wpdb {
  public $log = array();
  public function esc_like($s) { return $s; }
  public function prepare($q, ...$args) {
    $i = 0;
    return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) { return (string) $args[$i++]; }, $q);
  }
  public function get_results($sql, $mode = null) {
    return array(array('id' => 'c1', 'name' => 'Alpha', 'clean_name' => 'Alpha', 'group_name' => 'Base Set', 'sub_type_name' => 'Normal'));
  }
  public function query($sql) { $this->log[] = $sql; return 1; }
}
global $wpdb; $wpdb = new Fake_Wpdb();
require_once '${PLUGIN_ROOT}/includes/class-card-vault-catalog-importer.php';
$res = Card_Vault_Catalog_Importer::ingest_pricecharting_csv('${file}');
echo json_encode(array('res' => $res, 'log' => $wpdb->log, 'hist' => Card_Vault_Price_History::$rows));
`;
  try {
    return JSON.parse(execFileSync('/usr/bin/php', ['-d', 'display_errors=stderr'], { input: code, encoding: 'utf8', stdio: ['pipe', 'pipe', 'ignore'] }));
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

describe('PriceCharting ingest', () => {
  it('maps graded-price to PSA 9 and manual-only-price to PSA 10', () => {
    const { log, hist } = ingest('product-name,console-name,loose-price,graded-price,manual-only-price\nAlpha #4,Pokemon Base Set,$5.00,$310.00,$1450.00\n');
    assert.ok(log[0].includes('psa9_price = CASE WHEN 310 > 0 THEN 310'), log[0]);
    assert.ok(log[0].includes('psa10_price = CASE WHEN 1450 > 0 THEN 1450'), log[0]);
    assert.strictEqual(hist[0].psa_9, 310);
    assert.strictEqual(hist[0].psa_10, 1450);
  });

  it('keeps explicit psa9price and psa10price headers ahead of the PriceCharting names', () => {
    const { hist } = ingest('product-name,console-name,psa9-price,psa10-price,graded-price,manual-only-price\nAlpha #4,Pokemon Base Set,20,40,310,1450\n');
    assert.strictEqual(hist[0].psa_9, 20);
    assert.strictEqual(hist[0].psa_10, 40);
  });

  it('divides a pennies column by 100 even at 500 or less', () => {
    const { hist } = ingest('product-name,console-name,price-in-pennies,psa10-price\nAlpha #4,Pokemon Base Set,350,90\n');
    assert.strictEqual(hist[0].raw_market, 3.5);
  });
});
