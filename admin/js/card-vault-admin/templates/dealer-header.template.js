/**
 * Card Vault Dealer HQ template partial: page header with the Scan Card and Launch POS actions.
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const dealerHeaderTemplate = `
        <!-- Top Banner Header -->
        <x-card variant="glass" class="pa-6 mb-6">
          <v-row align="center" no-gutters>
            <v-col cols="12" md="8">
              <v-sheet color="transparent" class="d-flex align-center">
                <v-avatar size="56" color="primary" variant="tonal" class="mr-4">
                  <v-icon icon="fad fa-cards-blank" size="32" color="#62c9ff" />
                </v-avatar>
                <v-sheet color="transparent">
                  <h1 class="text-h4 font-weight-black text-uppercase tracking-wider mb-1 text-white">
                    Card Vault <span style="color: #62c9ff;">Dealer HQ</span>
                  </h1>
                  <p class="text-caption text-medium-emphasis mb-0">
                    Offline-first Trade Desk, Optical Grading, Consignment & WooCommerce Sync
                  </p>
                </v-sheet>
              </v-sheet>
            </v-col>
            <v-col cols="12" md="4" class="text-md-right mt-4 mt-md-0 d-flex justify-md-end ga-2">
              <x-btn
                variant="tonal"
                color="secondary"
                prepend-icon="fad fa-camera"
                @click="isScanModalOpen = true"
              >
                Scan Card
              </x-btn>
              <x-btn
                color="primary"
                variant="flat"
                prepend-icon="fal fa-external-link"
                @click="launchPosApp"
              >
                Launch POS
              </x-btn>
            </v-col>
          </v-row>
        </x-card>
`;
