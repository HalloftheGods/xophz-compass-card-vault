/**
 * Dealer HQ clawback visibility: the summary metric and the ledger row view.
 * Zero mock or synthetic data: the fetch stub only replays the field the server's summary route returns.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import assert from 'node:assert';

globalThis.window = {
  Compass: { Vue: { ref: (value) => ({ value }), computed: (getter) => ({ get value() { return getter(); } }) } }
};

const { useDealerData } = await import('../admin/js/card-vault-admin/use-dealer-data.js');
const { usePayoutLedger } = await import('../admin/js/card-vault-admin/use-payout-ledger.js');

const jsonResponse = (data) => ({ ok: true, status: 200, json: async () => ({ data }) });

describe('Dealer HQ clawbacks', () => {
  it('reads total_clawback_due into metrics.clawbackDue', async () => {
    const original = globalThis.fetch;
    globalThis.fetch = async (url) => jsonResponse(String(url).endsWith('/dealer/summary') ? { total_clawback_due: 85 } : []);
    try {
      const dealer = useDealerData({ context: {} }, () => {});
      assert.strictEqual(dealer.metrics.value.clawbackDue, 0);
      await dealer.fetchAllData();
      assert.strictEqual(dealer.metrics.value.clawbackDue, 85);
    } finally {
      globalThis.fetch = original;
    }
  });

  it('describes clawback and void rows without calling them settled payouts', () => {
    const ledger = usePayoutLedger({ context: {} }, { value: [] }, () => {}, async () => {});
    const claw = ledger.formatPayout({ payout_status: 'clawback', consignor_payout_amount: '-85.00' });
    assert.strictEqual(claw.amountText, 'Owed back $85.00');
    assert.strictEqual(claw.statusText, 'Owed back by consignor');
    const voided = ledger.formatPayout({ payout_status: 'void', consignor_payout_amount: '85.00' });
    assert.strictEqual(voided.amountText, 'Voided, nothing due');
    assert.strictEqual(voided.statusText, 'Voided');
    const paid = ledger.formatPayout({ payout_status: 'paid', consignor_payout_amount: '85.00' });
    assert.strictEqual(paid.amountText, '$85.00');
    assert.strictEqual(paid.statusText, 'Settled');
  });

  it('filters by void and clawback', () => {
    const rows = [{ payout_status: 'void' }, { payout_status: 'clawback' }, { payout_status: 'paid' }];
    const ledger = usePayoutLedger({ context: {} }, { value: rows }, () => {}, async () => {});
    ledger.payoutFilter.value = 'clawback';
    assert.strictEqual(ledger.filteredPayouts.value.length, 1);
    ledger.payoutFilter.value = 'void';
    assert.strictEqual(ledger.filteredPayouts.value[0].payout_status, 'void');
  });
});
