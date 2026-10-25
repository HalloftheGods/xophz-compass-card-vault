/**
 * Card_Vault_Catalog_CLI and crawler error reporting specs (source-text checks).
 * They guard the text of the code; they do not execute WP-CLI.
 * Strictly zero em dashes in code, copy, comments, and reports.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (f) => readFileSync(path.join(ROOT, 'includes', f), 'utf8');

describe('catalog crawler and CLI error reporting (#6627)', () => {
  it('process_next_step returns an error key on failure', () => {
    assert.match(read('class-card-vault-catalog-crawler.php'), /'error'\s*=>\s*\$is_success \? null/);
  });
  it('CLI step and daemon branches print the error key', () => {
    const cli = read('class-card-vault-catalog-cli.php');
    assert.match(cli, /Step failed: ' \. \( \$res\['error'\]/);
    assert.match(cli, /\$step\['error'\]/);
  });
  it('CLI rejects --categories that yields no valid IDs', () => {
    assert.match(read('class-card-vault-catalog-cli.php'), /No valid category IDs in --categories/);
  });
});
