/**
 * Payouts ledger: status filter ("all", "unpaid", "paid", "void", "clawback") over the loaded payouts, and settlement.
 * markPayoutSettled(id) posts to /payouts/settle and reloads Dealer HQ data through refresh() when the
 * response reports success; a request error or a success:false response shows a toast via notify.
 */
import { cardVaultRequest } from "./card-vault-rest.js";

const { ref, computed } = window.Compass.Vue;

export const usePayoutLedger = (props, payouts, notify, refresh) => {
  const payoutFilter = ref("all");

  const filteredPayouts = computed(() => {
    const showsAll = payoutFilter.value === "all";
    if (showsAll) return payouts.value;
    return payouts.value.filter((p) => p.payout_status === payoutFilter.value);
  });

  // What the dealer should read for a row: void rows owe nothing, clawback rows are money the
  // consignor owes back (stored as a negative payout), everything else keeps the stored amount.
  const formatPayout = (p) => {
    const money = Math.abs(Number(p.consignor_payout_amount)).toFixed(2);
    const views = {
      void: { amountText: "Voided, nothing due", amountClass: "text-medium-emphasis", statusText: "Voided", chipColor: "default" },
      clawback: { amountText: `Owed back $${money}`, amountClass: "text-warning", statusText: "Owed back by consignor", chipColor: "error" },
      paid: { amountText: `$${money}`, amountClass: "text-success", statusText: "Settled", chipColor: "success" }
    };
    const unpaid = { amountText: `$${Number(p.consignor_payout_amount).toFixed(2)}`, amountClass: "text-success", statusText: "", chipColor: "warning" };
    return views[p.payout_status] || unpaid;
  };

  const hasPayouts = computed(() => payouts.value.length > 0);

  const markPayoutSettled = async (payoutId) => {
    try {
      // Keep the method already recorded on the payout row; send none when the row has none
      // (the server then applies its own default). No reference is invented here.
      const row = payouts.value.find((p) => p.id === payoutId);
      const method = row?.payment_method || row?.consignor_payout_method;
      const body = { payout_id: payoutId };
      if (method) body.payment_method = method;
      const res = await cardVaultRequest(props.context, "POST", "/payouts/settle", body);

      const isSettled = res.success === true;
      if (isSettled) {
        notify("Payout record marked as settled.");
        await refresh();
      } else {
        notify(res.message || "Payout was not settled.");
        await refresh();
      }
    } catch (err) {
      notify(`Failed to settle payout record: ${err.message}`);
      // The row may have changed since load (paid or voided): reload so the ledger shows its real status.
      try {
        await refresh();
      } catch (refreshErr) {
        notify(`Could not reload payouts: ${refreshErr.message}`);
      }
    }
  };

  return { payoutFilter, filteredPayouts, formatPayout, hasPayouts, markPayoutSettled };
};
