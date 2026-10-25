/**
 * Catalog REST abuse-guard specs (source-text checks, #6629).
 * They guard the text of the code; they do not execute WordPress.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const src = readFileSync(path.join(ROOT, 'includes', 'class-card-vault-catalog-rest.php'), 'utf8');

describe('catalog REST abuse guards (#6629)', () => {
  it('search rejects pages beyond the browsable range with a 400', () => {
    assert.match(src, /'page_too_deep'.*'status' => 400/);
  });
  it('status honors check only for admins', () => {
    assert.match(src, /get_param\( 'check' \) && true === self::check_admin_permission/);
  });
  it('status caches the aggregate for non-admin callers in a transient', () => {
    assert.match(src, /get_transient\( 'card_vault_catalog_status_public' \)/);
    assert.match(src, /set_transient\( 'card_vault_catalog_status_public'/);
  });
});
