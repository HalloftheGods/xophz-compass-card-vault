/**
 * Card Vault REST target for one Dealer HQ request.
 * Uses the host context's api client and restUrl when given, else window.Compass.api and "/wp-json/".
 * api is null when neither source has a client; callers decide what that means.
 */
export const resolveCardVaultRest = (context) => {
  const api = context?.api || window.Compass?.api || null;
  const restUrl = context?.restUrl || "/wp-json/";
  return { api, base: `${restUrl}xophz-card-vault/v1` };
};
