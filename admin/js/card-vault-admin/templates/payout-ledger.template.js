/**
 * Card Vault Dealer HQ template partial: payouts ledger with status filter, settle action and empty state.
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const payoutLedgerTemplate = `
        <!-- SECTION: Payouts Ledger -->
        <v-sheet v-if="currentSection === 'payouts' || currentSection === 'overview'" color="transparent" class="mb-6">
          <x-card variant="glass" class="pa-6">
            <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-4">
              <v-sheet color="transparent">
                <h3 class="text-h6 font-weight-bold text-white">Payouts & Disbursements</h3>
                <p class="text-caption text-medium-emphasis mb-0">Pending consignor liabilities and historical batch disbursals</p>
              </v-sheet>
              <v-sheet color="transparent" class="d-flex ga-2">
                <x-btn
                  size="x-small"
                  :variant="payoutFilter === 'all' ? 'flat' : 'text'"
                  :color="payoutFilter === 'all' ? 'primary' : 'default'"
                  @click="payoutFilter = 'all'"
                >
                  All
                </x-btn>
                <x-btn
                  size="x-small"
                  :variant="payoutFilter === 'unpaid' ? 'flat' : 'text'"
                  :color="payoutFilter === 'unpaid' ? 'warning' : 'default'"
                  @click="payoutFilter = 'unpaid'"
                >
                  Pending ({{ metrics.pendingLiability > 0 ? '$' + metrics.pendingLiability : '0' }})
                </x-btn>
                <x-btn
                  size="x-small"
                  :variant="payoutFilter === 'paid' ? 'flat' : 'text'"
                  :color="payoutFilter === 'paid' ? 'success' : 'default'"
                  @click="payoutFilter = 'paid'"
                >
                  Settled
                </x-btn>
              </v-sheet>
            </v-sheet>

            <v-table v-if="hasPayouts && !loadError" class="bg-transparent">
              <thead>
                <tr>
                  <th class="text-left text-medium-emphasis font-weight-bold">RECORD #</th>
                  <th class="text-left text-medium-emphasis font-weight-bold">CONSIGNOR</th>
                  <th class="text-right text-medium-emphasis font-weight-bold">SALE GROSS</th>
                  <th class="text-right text-medium-emphasis font-weight-bold">PAYOUT DUE</th>
                  <th class="text-center text-medium-emphasis font-weight-bold">STATUS</th>
                  <th class="text-right text-medium-emphasis font-weight-bold">ACTION</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in filteredPayouts" :key="p.id">
                  <td class="font-mono text-caption text-white">{{ p.sale_record_id || '#' + p.id }}</td>
                  <td class="font-weight-medium">{{ p.consignor_name || 'Consignor ' + p.consignor_id }}</td>
                  <td class="text-right font-weight-medium">\${{ Number(p.total_sale_amount).toFixed(2) }}</td>
                  <td class="text-right font-weight-bold text-success">\${{ Number(p.consignor_payout_amount).toFixed(2) }}</td>
                  <td class="text-center">
                    <x-chip size="x-small" :color="p.payout_status === 'paid' ? 'success' : 'warning'" variant="tonal">
                      {{ p.payout_status }}
                    </x-chip>
                  </td>
                  <td class="text-right">
                    <x-btn
                      v-if="p.payout_status === 'unpaid'"
                      size="x-small"
                      color="primary"
                      variant="tonal"
                      @click="markPayoutSettled(p.id)"
                    >
                      Settle Disbursal
                    </x-btn>
                    <span v-else class="text-caption text-medium-emphasis">Settled</span>
                  </td>
                </tr>
              </tbody>
            </v-table>

            <v-sheet v-else-if="!loadError" color="transparent" class="pa-10 text-center">
              <v-icon icon="fad fa-receipt" size="48" class="mb-3" color="#64748b" />
              <h4 class="text-subtitle-1 font-weight-bold text-white">No Payout Records Found</h4>
              <p class="text-caption text-medium-emphasis mb-0">Consignment sales recorded at the POS automatically generate itemized ledger payouts.</p>
            </v-sheet>
          </x-card>
        </v-sheet>
`;
