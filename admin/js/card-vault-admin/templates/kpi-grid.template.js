/**
 * Card Vault Dealer HQ template partial: overview KPI cards (gross sales, dealer profit, pending liability, paid out).
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const kpiGridTemplate = `
        <!-- KPI Metric Grid (Dealer HQ Overview) -->
        <v-row v-if="currentSection === 'overview'" class="mb-6">
          <!-- Gross Sales -->
          <v-col cols="12" sm="6" md="3">
            <x-card variant="glass" class="pa-4 fill-height">
              <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-2">
                <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Gross Sales</span>
                <v-icon icon="fad fa-dollar-sign" color="#62c9ff" size="20" />
              </v-sheet>
              <div class="text-h4 font-weight-bold" style="color: #62c9ff;">
                {{ loadError ? '--' : formatUsd(metrics.grossSales) }}
              </div>
              <div class="text-caption text-medium-emphasis mt-1">Consignment + Showcase Sales</div>
            </x-card>
          </v-col>

          <!-- Dealer Profit -->
          <v-col cols="12" sm="6" md="3">
            <x-card variant="glass" class="pa-4 fill-height">
              <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-2">
                <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Dealer Profit</span>
                <v-icon icon="fad fa-chart-line" color="#00e676" size="20" />
              </v-sheet>
              <div class="text-h4 font-weight-bold text-white">
                {{ loadError ? '--' : formatUsd(metrics.dealerProfit) }}
              </div>
              <div class="text-caption text-medium-emphasis mt-1">Retained Commissions</div>
            </x-card>
          </v-col>

          <!-- Pending Liability -->
          <v-col cols="12" sm="6" md="3">
            <x-card variant="glass" class="pa-4 fill-height">
              <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-2">
                <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Pending Liability</span>
                <v-icon icon="fad fa-clock" color="#ffb300" size="20" />
              </v-sheet>
              <div class="text-h4 font-weight-bold text-white">
                {{ loadError ? '--' : formatUsd(metrics.pendingLiability) }}
              </div>
              <div class="text-caption text-medium-emphasis mt-1">Unpaid Consignor Balances</div>
            </x-card>
          </v-col>

          <!-- Total Paid Out -->
          <v-col cols="12" sm="6" md="3">
            <x-card variant="glass" class="pa-4 fill-height">
              <v-sheet color="transparent" class="d-flex justify-space-between align-center mb-2">
                <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Settled Disbursals</span>
                <v-icon icon="fad fa-check-circle" color="#38bdf8" size="20" />
              </v-sheet>
              <div class="text-h4 font-weight-bold text-white">
                {{ loadError ? '--' : formatUsd(metrics.totalPaidOut) }}
              </div>
              <div class="text-caption text-medium-emphasis mt-1">Total Payouts Distributed</div>
            </x-card>
          </v-col>
        </v-row>
`;
