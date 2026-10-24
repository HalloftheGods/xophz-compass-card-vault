/**
 * Card Vault Dealer HQ template partial: consignor directory table and its empty state.
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const consignorDirectoryTemplate = `
        <!-- SECTION: Consignors Table & Directory -->
        <v-sheet v-if="currentSection === 'consignors' || currentSection === 'overview'" color="transparent" class="mb-6">
          <x-card variant="glass" class="pa-6">
            <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-4">
              <v-sheet color="transparent">
                <h3 class="text-h6 font-weight-bold text-white">Consignor Directory</h3>
                <p class="text-caption text-medium-emphasis mb-0">Active consignors, split rates, and account settlement preferences</p>
              </v-sheet>
              <x-btn
                variant="tonal"
                color="primary"
                size="small"
                prepend-icon="fad fa-user-plus"
                @click="isConsignorModalOpen = true"
              >
                Register Consignor
              </x-btn>
            </v-sheet>

            <v-table v-if="hasConsignors" class="bg-transparent">
              <thead>
                <tr>
                  <th class="text-left text-medium-emphasis font-weight-bold">CONSIGNOR</th>
                  <th class="text-left text-medium-emphasis font-weight-bold">CONTACT</th>
                  <th class="text-center text-medium-emphasis font-weight-bold">SPLIT RATE</th>
                  <th class="text-center text-medium-emphasis font-weight-bold">PAYOUT CHANNEL</th>
                  <th class="text-center text-medium-emphasis font-weight-bold">STATUS</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in consignors" :key="c.consignor_id">
                  <td class="font-weight-bold text-white">{{ c.name }}</td>
                  <td class="text-caption text-medium-emphasis">{{ c.email || c.phone || 'No direct contact' }}</td>
                  <td class="text-center">
                    <x-chip size="x-small" color="primary" variant="tonal" class="font-weight-bold">
                      {{ c.default_split_rate }}% Consignor
                    </x-chip>
                  </td>
                  <td class="text-center text-caption">{{ c.payout_method }} ({{ c.payout_handle || 'Default' }})</td>
                  <td class="text-center">
                    <x-chip size="x-small" :color="c.status === 'active' ? 'success' : 'default'" variant="tonal">
                      {{ c.status }}
                    </x-chip>
                  </td>
                </tr>
              </tbody>
            </v-table>

            <v-sheet v-else color="transparent" class="pa-10 text-center">
              <v-icon icon="fad fa-users-slash" size="48" class="mb-3" color="#64748b" />
              <h4 class="text-subtitle-1 font-weight-bold text-white">No Consignors Registered</h4>
              <p class="text-caption text-medium-emphasis mb-4">Register your first consignor to track inventory splits and automated ledger payouts.</p>
              <x-btn variant="tonal" color="primary" size="small" @click="isConsignorModalOpen = true">
                Add New Consignor
              </x-btn>
            </v-sheet>
          </x-card>
        </v-sheet>
`;
