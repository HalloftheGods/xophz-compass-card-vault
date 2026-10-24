/**
 * USD display string for a dollar amount; a missing or zero amount shows "$0.00".
 */
const usd = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });

export const formatUsd = (amount) => usd.format(amount || 0);
