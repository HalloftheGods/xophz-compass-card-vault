/**
 * Dealer HQ data: summary metrics, consignors and payouts from the Card Vault REST routes.
 * fetchAllData() loads all three in parallel. A failed or empty response keeps the values
 * already held (zeros and empty lists before the first load) and shows a toast via notify.
 */
import { resolveCardVaultRest } from "./card-vault-rest.js";

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
  return isFulfilled ? result.value?.data?.data : undefined;
};

export const useDealerData = (props, notify) => {
  const isLoadingData = ref(false);
  const metrics = ref(emptyMetrics());
  const consignors = ref([]);
  const payouts = ref([]);

  const applyResults = (results) => {
    const [summaryRes, consignorsRes, payoutsRes] = results;
    const anyRejected = results.some(isRejected);
    if (anyRejected) {
      notify("Could not load all Card Vault data. Showing what was already loaded.");
    }

    const summary = settledPayload(summaryRes);
    if (summary) metrics.value = toMetrics(summary);

    const consignorRows = settledPayload(consignorsRes);
    if (consignorRows) consignors.value = consignorRows;

    const payoutRows = settledPayload(payoutsRes);
    if (payoutRows) payouts.value = payoutRows;
  };

  const fetchAllData = async () => {
    isLoadingData.value = true;
    try {
      const { api, base } = resolveCardVaultRest(props.context);
      if (!api) return;

      // Parallel live queries to Card Vault endpoints
      const results = await Promise.allSettled([
        api.get(`${base}/dealer/summary`),
        api.get(`${base}/consignors`),
        api.get(`${base}/payouts`)
      ]);
      applyResults(results);
    } catch (err) {
      notify("Could not load Card Vault data. Showing what was already loaded.");
    } finally {
      isLoadingData.value = false;
    }
  };

  return { isLoadingData, metrics, consignors, payouts, fetchAllData };
};
