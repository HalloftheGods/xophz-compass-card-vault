/**
 * Card Vault Submodule Test Runner
 * Strictly zero em dashes in code, copy, comments, and reports.
 * Zero mock or synthetic data.
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './card-vault.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  console.error(`Card Vault Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`Card Vault Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
