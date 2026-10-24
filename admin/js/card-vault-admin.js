/**
 * Card Vault - Compass Admin Extension View
 * Implements Dealer HQ, Consignor Management, Payout Ledger, and Trade Desk Configuration.
 * Fully integrated into the Compass Starship UI.
 *
 * This classic script is the plugin's script_url. It registers "card-vault" synchronously with an
 * async component; the view itself is the ES module card-vault-admin/card-vault-admin.component.js,
 * imported (with its composables and template partials) the first time Compass renders it.
 * If that import fails, the view shows the load error instead of Dealer HQ.
 */

(function () {
  if (!window.Compass) {
    console.error("[CardVaultAdmin] Compass runtime bridge not found on window.Compass.");
    return;
  }

  const { defineAsyncComponent, h } = window.Compass.Vue;

  // Pre-compile template if runtime compiler is present
  const precompile = (CardVaultAdmin) => {
    if (typeof window.Compass.Vue?.compile === "function") {
      try {
        CardVaultAdmin.render = window.Compass.Vue.compile(CardVaultAdmin.template);
      } catch (e) {
        // Leave render unset so Vue compiles the template at mount time and reports the real error.
        CardVaultAdmin.render = undefined;
        CardVaultAdmin.precompileError = e;
        console.error("[CardVaultAdmin] Template pre-compile failed; Vue will retry at mount.", e);
      }
    }
    return CardVaultAdmin;
  };

  // Relative to this script's URL (the base URL of a classic script).
  const loadCardVaultAdmin = () =>
    import("./card-vault-admin/card-vault-admin.component.js").then((module) =>
      precompile(module.CardVaultAdmin)
    );

  const CardVaultAdminLoadError = {
    name: "CardVaultAdminLoadError",
    inheritAttrs: false,
    props: ["error"],
    render() {
      const reason = this.error?.message || "unknown error";
      return h("p", { class: "pa-6 text-error" }, `Card Vault admin view failed to load: ${reason}`);
    }
  };

  const CardVaultAdmin = defineAsyncComponent({
    loader: loadCardVaultAdmin,
    errorComponent: CardVaultAdminLoadError
  });

  // Register with Compass Core
  window.Compass.registerPlugin("card-vault", {
    component: CardVaultAdmin
  });

  console.log("[CardVaultAdmin] Successfully registered 'card-vault' with Compass Bridge.");
})();
