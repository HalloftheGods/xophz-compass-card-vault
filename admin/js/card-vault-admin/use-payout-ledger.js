/**
 * Payouts ledger: status filter ("all", "unpaid", "paid") over the loaded payouts, and settlement.
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

  const hasPayouts = computed(() => payouts.value.length > 0);

  const markPayoutSettled = async (payoutId) => {
    try {
      const res = await cardVaultRequest(props.context, "POST", "/payouts/settle", {
        payout_id: payoutId,
        payment_method: "Cash",
        payment_reference: "Disbursed via Dealer HQ"
      });

      const isSettled = res.success === true;
      if (isSettled) {
        notify("Payout record marked as settled.");
        await refresh();
      } else {
        notify(res.message || "Payout was not settled.");
      }
    } catch (err) {
      notify(`Failed to settle payout record: ${err.message}`);
    }
  };

  return { payoutFilter, filteredPayouts, hasPayouts, markPayoutSettled };
};
