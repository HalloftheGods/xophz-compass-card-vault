/**
 * Card Vault Dealer HQ template: the page shell composed from the section partials in templates/.
 * Every partial binds to the CardVaultAdmin setup() bindings in one component scope.
 */
import { dealerHeaderTemplate } from "./templates/dealer-header.template.js";
import { kpiGridTemplate } from "./templates/kpi-grid.template.js";
import { consignorDirectoryTemplate } from "./templates/consignor-directory.template.js";
import { payoutLedgerTemplate } from "./templates/payout-ledger.template.js";
import { tradeSettingsTemplate } from "./templates/trade-settings.template.js";
import { consignorDialogTemplate } from "./templates/consignor-dialog.template.js";
import { scanDialogTemplate } from "./templates/scan-dialog.template.js";

export const cardVaultAdminTemplate = `
      <v-container fluid class="pa-6 max-w-7xl mx-auto">
        <!-- Action Notification Banner -->
        <x-snackbar v-model="showToast" :timeout="3500" color="surface-variant" variant="flat">
          {{ toastMessage }}
        </x-snackbar>

${dealerHeaderTemplate}
        <v-alert v-if="loadError" type="error" variant="tonal" class="mb-6">
          Card Vault data could not be loaded, so balances and ledgers are not shown: {{ loadError }}
        </v-alert>
${kpiGridTemplate}
${consignorDirectoryTemplate}
${payoutLedgerTemplate}
${tradeSettingsTemplate}
${consignorDialogTemplate}
${scanDialogTemplate}
      </v-container>
    `;
