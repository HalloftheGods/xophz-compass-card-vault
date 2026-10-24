/**
 * Card Vault REST client for Dealer HQ.
 * cardVaultRequest(context, method, path, body) calls /wp-json/xophz-card-vault/v1<path> with fetch,
 * same-origin credentials and the X-WP-Nonce from the host context (or window.xophzCompassSettings).
 * It resolves to the parsed JSON body. It rejects with an Error carrying the server's message
 * (message or error field) or the HTTP status when the response is not 2xx or not JSON.
 * It does not retry, and it cannot add a nonce when the host provides none.
 */
const errorMessage = (body, status) => body?.message || body?.error || `Request failed (HTTP ${status}).`;

export const cardVaultRequest = async (context, method, path, body) => {
  const restUrl = context?.restUrl || window.xophzCompassSettings?.restUrl || "/wp-json/";
  const nonce = context?.nonce || window.xophzCompassSettings?.nonce || "";
  const headers = { "X-WP-Nonce": nonce };
  const init = { method, credentials: "same-origin", headers };
  const hasBody = body !== undefined;
  if (hasBody) {
    headers["Content-Type"] = "application/json";
    init.body = JSON.stringify(body);
  }

  const res = await fetch(`${restUrl}xophz-card-vault/v1${path}`, init);
  const parsed = await res.json().catch(() => null);
  const isFailure = !res.ok;
  if (isFailure) throw new Error(errorMessage(parsed, res.status));
  const isNotJson = parsed === null;
  if (isNotJson) throw new Error(`Card Vault returned a non-JSON response (HTTP ${res.status}).`);
  return parsed;
};
