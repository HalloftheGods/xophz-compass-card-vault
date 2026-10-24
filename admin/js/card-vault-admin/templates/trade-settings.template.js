/**
 * Card Vault Dealer HQ template partial: trade desk settings section.
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const tradeSettingsTemplate = `
        <!-- SECTION: Trade Desk Settings & Multimodal Grading -->
        <v-sheet v-if="currentSection === 'settings'" color="transparent" class="mb-6">
          <x-card variant="glass" class="pa-6">
            <h3 class="text-h6 font-weight-bold text-white mb-2">Trade Desk Configuration</h3>
            <p class="text-caption text-medium-emphasis mb-6">Default buyout margin multipliers, optical scanning thresholds & WooCommerce inventory synchronization</p>

            <v-row>
              <v-col cols="12" md="6">
                <x-card class="pa-4 fill-height" variant="glass">
                  <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-1">
                    <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Cash Buyout Rate</span>
                    <x-chip size="x-small" color="primary" variant="tonal">{{ tradeSettings.cashBuyoutRate }}%</x-chip>
                  </v-sheet>
                  <div class="text-h6 font-weight-bold text-white">TCG Market Cash Baseline</div>
                  <p class="text-caption text-medium-emphasis mt-1 mb-0">Default cash buyout offer percentage calculated against live TCG market pricing.</p>
                </x-card>
              </v-col>

              <v-col cols="12" md="6">
                <x-card class="pa-4 fill-height" variant="glass">
                  <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-1">
                    <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Trade Credit Rate</span>
                    <x-chip size="x-small" color="secondary" variant="tonal">{{ tradeSettings.tradeBuyoutRate }}%</x-chip>
                  </v-sheet>
                  <div class="text-h6 font-weight-bold text-white">Store Credit Multiplier</div>
                  <p class="text-caption text-medium-emphasis mt-1 mb-0">Higher trade-in value awarded when customers exchange cards for store merchandise.</p>
                </x-card>
              </v-col>

              <v-col cols="12" class="mt-4">
                <x-card class="pa-4" variant="glass">
                  <v-sheet color="transparent" class="d-flex justify-space-between align-center">
                    <v-sheet color="transparent">
                      <div class="font-weight-bold text-white">WooCommerce Product Sync & Auto-Delist</div>
                      <div class="text-caption text-medium-emphasis">Automatically archive or delist WooCommerce items when physical POS transactions deplete stock to zero.</div>
                    </v-sheet>
                    <v-switch v-model="tradeSettings.autoDelistSync" color="primary" hide-details density="compact" />
                  </v-sheet>
                </x-card>
              </v-col>
            </v-row>
          </x-card>
        </v-sheet>
`;
