/**
 * Dealer HQ data: summary metrics, consignors and payouts from the Card Vault REST routes.
 * fetchAllData() loads all three in parallel. When any request fails, loadError holds the reasons
 * (the views show an error state, not zeros or empty tables) and notify shows a toast; values from
 * an earlier successful load are kept in memory but the views hide them while loadError is set.
 */
import { cardVaultRequest } from "./card-vault-rest.js";

const { ref } = window.Compass.Vue;

const emptyMetrics = () => ({
  activeConsignors: 0,
  itemsSold: 0,
  grossSales: 0,
  pendingLiability: 0,
  totalPaidOut: 0,
  dealerProfit: 0,
  activeStock: 0
});

const toMetrics = (d) => ({
  activeConsignors: d.active_consignors_count || 0,
  itemsSold: d.total_items_sold || 0,
  grossSales: d.total_gross_sales || 0,
  pendingLiability: d.total_pending_liability || 0,
  totalPaidOut: d.total_paid_out || 0,
  dealerProfit: d.total_dealer_profit || 0,
  activeStock: d.active_inventory_count || 0
});

const isRejected = (result) => result.status === "rejected";

// The `data` envelope of a fulfilled request, or undefined when it was rejected or empty.
const settledPayload = (result) => {
  const isFulfilled = result.status === "fulfilled";
  return isFulfilled ? result.value?.data : undefined;
};

const failureReason = (result) => result.reason?.message || "unknown error";

export const useDealerData = (props, notify) => {
  const metrics = ref(emptyMetrics());
  const loadError = ref("");
  const consignors = ref([]);
  const payouts = ref([]);

  const applyResults = (results) => {
    const [summaryRes, consignorsRes, payoutsRes] = results;
    const rejected = results.filter(isRejected);
    const anyRejected = rejected.length > 0;
    loadError.value = anyRejected ? rejected.map(failureReason).join("; ") : "";
    if (anyRejected) {
      notify(`Could not load all Card Vault data: ${loadError.value}`);
    }

    const summary = settledPayload(summaryRes);
    if (summary) metrics.value = toMetrics(summary);

    const consignorRows = settledPayload(consignorsRes);
    if (consignorRows) consignors.value = consignorRows;

    const payoutRows = settledPayload(payoutsRes);
    if (payoutRows) payouts.value = payoutRows;
  };

  const fetchAllData = async () => {
    try {
      // Parallel live queries to Card Vault endpoints
      const results = await Promise.allSettled([
        cardVaultRequest(props.context, "GET", "/dealer/summary"),
        cardVaultRequest(props.context, "GET", "/consignors"),
        cardVaultRequest(props.context, "GET", "/payouts")
      ]);
      applyResults(results);
    } catch (err) {
      loadError.value = err?.message || "unknown error";
      notify(`Could not load Card Vault data: ${loadError.value}`);
    }
  };

  return { metrics, loadError, consignors, payouts, fetchAllData };
};
