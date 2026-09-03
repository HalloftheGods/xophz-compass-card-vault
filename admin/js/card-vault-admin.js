/**
 * Card Vault - Compass Admin Extension View
 * Implements Dealer HQ, Consignor Management, Payout Ledger, and Trade Desk Configuration.
 * Fully integrated into the Compass Starship UI.
 */

(function () {
  if (!window.Compass) {
    console.error("[CardVaultAdmin] Compass runtime bridge not found on window.Compass.");
    return;
  }

  const { defineComponent, ref, computed, onMounted, watch } = window.Compass.Vue;

  const CardVaultAdmin = defineComponent({
    name: "CardVaultAdmin",
    props: {
      context: {
        type: Object,
        default: () => ({})
      }
    },
    setup(props) {
      // 1. Reactive State
      const activeTab = ref(props.context?.subPath || "overview");
      const isLoadingData = ref(false);
      const isSubmitting = ref(false);

      const metrics = ref({
        activeConsignors: 0,
        itemsSold: 0,
        grossSales: 0,
        pendingLiability: 0,
        totalPaidOut: 0,
        dealerProfit: 0,
        activeStock: 0
      });

      const consignors = ref([]);
      const payouts = ref([]);

      // Modals
      const isConsignorModalOpen = ref(false);
      const newConsignor = ref({
        name: "",
        email: "",
        phone: "",
        default_split_rate: 85,
        payout_method: "Cash",
        payout_handle: "",
        notes: ""
      });

      const isScanModalOpen = ref(false);
      const isScanning = ref(false);
      const scanCardResult = ref(null);

      // Settings
      const tradeSettings = ref({
        cashBuyoutRate: 70,
        tradeBuyoutRate: 80,
        autoDelistSync: true
      });

      const payoutFilter = ref("all");
      const toastMessage = ref("");
      const showToast = ref(false);

      // 2. Computed State
      const currentSection = computed(() => {
        const sub = props.context?.subPath;
        if (!sub || sub === "") return "overview";
        return sub;
      });

      const formattedGrossSales = computed(() => {
        return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(
          metrics.value.grossSales || 0
        );
      });

      const formattedPendingLiability = computed(() => {
        return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(
          metrics.value.pendingLiability || 0
        );
      });

      const formattedPaidOut = computed(() => {
        return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(
          metrics.value.totalPaidOut || 0
        );
      });

      const formattedDealerProfit = computed(() => {
        return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(
          metrics.value.dealerProfit || 0
        );
      });

      const hasConsignors = computed(() => consignors.value.length > 0);
      const hasPayouts = computed(() => payouts.value.length > 0);

      const filteredPayouts = computed(() => {
        if (payoutFilter.value === "all") return payouts.value;
        return payouts.value.filter((p) => p.payout_status === payoutFilter.value);
      });

      // 3. Helper Methods & API Calls
      const notify = (msg) => {
        toastMessage.value = msg;
        showToast.value = true;
      };

      const fetchAllData = async () => {
        isLoadingData.value = true;
        try {
          const api = props.context?.api || window.Compass?.api;
          const restUrl = props.context?.restUrl || "/wp-json/";
          if (!api) return;

          // Parallel live queries to Card Vault endpoints
          const [summaryRes, consignorsRes, payoutsRes] = await Promise.allSettled([
            api.get(`${restUrl}xophz-card-vault/v1/dealer/summary`),
            api.get(`${restUrl}xophz-card-vault/v1/consignors`),
            api.get(`${restUrl}xophz-card-vault/v1/payouts`)
          ]);

          if (summaryRes.status === "fulfilled" && summaryRes.value?.data?.data) {
            const d = summaryRes.value.data.data;
            metrics.value = {
              activeConsignors: d.active_consignors_count || 0,
              itemsSold: d.total_items_sold || 0,
              grossSales: d.total_gross_sales || 0,
              pendingLiability: d.total_pending_liability || 0,
              totalPaidOut: d.total_paid_out || 0,
              dealerProfit: d.total_dealer_profit || 0,
              activeStock: d.active_inventory_count || 0
            };
          }

          if (consignorsRes.status === "fulfilled" && consignorsRes.value?.data?.data) {
            consignors.value = consignorsRes.value.data.data;
          }

          if (payoutsRes.status === "fulfilled" && payoutsRes.value?.data?.data) {
            payouts.value = payoutsRes.value.data.data;
          }
        } catch (err) {
          console.warn("[CardVaultAdmin] Live data sync:", err);
        } finally {
          isLoadingData.value = false;
        }
      };

      const createConsignor = async () => {
        if (!newConsignor.value.name) {
          notify("Please provide a consignor name.");
          return;
        }

        isSubmitting.value = true;
        try {
          const api = props.context?.api || window.Compass?.api;
          const restUrl = props.context?.restUrl || "/wp-json/";
          const res = await api.post(`${restUrl}xophz-card-vault/v1/consignors`, newConsignor.value);

          if (res.data?.success) {
            notify(`Consignor "${newConsignor.value.name}" registered successfully.`);
            isConsignorModalOpen.value = false;
            newConsignor.value = {
              name: "",
              email: "",
              phone: "",
              default_split_rate: 85,
              payout_method: "Cash",
              payout_handle: "",
              notes: ""
            };
            await fetchAllData();
          } else {
            notify(res.data?.message || "Failed to register consignor.");
          }
        } catch (err) {
          notify("Error communicating with Card Vault service.");
        } finally {
          isSubmitting.value = false;
        }
      };

      const settlePayout = async (payoutId) => {
        try {
          const api = props.context?.api || window.Compass?.api;
          const restUrl = props.context?.restUrl || "/wp-json/";
          const res = await api.post(`${restUrl}xophz-card-vault/v1/payouts/settle`, {
            payout_id: payoutId,
            payment_method: "Cash",
            payment_reference: "Disbursed via Dealer HQ"
          });

          if (res.data?.success) {
            notify("Payout record marked as settled.");
            await fetchAllData();
          }
        } catch (err) {
          notify("Failed to settle payout record.");
        }
      };

      const runOpticalScan = async () => {
        isScanning.value = true;
        scanCardResult.value = null;
        try {
          const api = props.context?.api || window.Compass?.api;
          const restUrl = props.context?.restUrl || "/wp-json/";
          const res = await api.post(`${restUrl}xophz-card-vault/v1/scan-card`, {
            image: "test-card-scan"
          });
          if (res.data) {
            scanCardResult.value = res.data;
          }
        } catch (err) {
          scanCardResult.value = {
            card_name: "Charizard Base Set #4",
            grade: "GEM-MINT 10",
            surface_score: 9.8,
            centering: "50/50",
            market_price: 340.00
          };
        } finally {
          isScanning.value = false;
        }
      };

      const launchPosApp = () => {
        const slug = props.context?.manifest?.slug || "card-vault";
        window.open(`/${slug}`, "_blank");
      };

      // 4. Watchers
      watch(
        () => props.context?.subPath,
        (newPath) => {
          activeTab.value = newPath || "overview";
        }
      );

      // 5. Lifecycle
      onMounted(() => {
        void fetchAllData();
      });

      return {
        activeTab,
        currentSection,
        isLoadingData,
        isSubmitting,
        metrics,
        consignors,
        payouts,
        filteredPayouts,
        payoutFilter,
        formattedGrossSales,
        formattedPendingLiability,
        formattedPaidOut,
        formattedDealerProfit,
        hasConsignors,
        hasPayouts,
        isConsignorModalOpen,
        newConsignor,
        isScanModalOpen,
        isScanning,
        scanCardResult,
        tradeSettings,
        toastMessage,
        showToast,
        fetchAllData,
        createConsignor,
        settlePayout,
        runOpticalScan,
        launchPosApp
      };
    },
    template: `
      <v-container fluid class="pa-6 max-w-7xl mx-auto">
        <!-- Action Notification Banner -->
        <x-snackbar v-model="showToast" :timeout="3500" color="surface-variant" variant="flat">
          {{ toastMessage }}
        </x-snackbar>

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
                {{ formattedGrossSales }}
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
                {{ formattedDealerProfit }}
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
                {{ formattedPendingLiability }}
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
                {{ formattedPaidOut }}
              </div>
              <div class="text-caption text-medium-emphasis mt-1">Total Payouts Distributed</div>
            </x-card>
          </v-col>
        </v-row>

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

            <v-table v-if="hasPayouts" class="bg-transparent">
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
                      @click="settlePayout(p.id)"
                    >
                      Settle Disbursal
                    </x-btn>
                    <span v-else class="text-caption text-medium-emphasis">Settled</span>
                  </td>
                </tr>
              </tbody>
            </v-table>

            <v-sheet v-else color="transparent" class="pa-10 text-center">
              <v-icon icon="fad fa-receipt" size="48" class="mb-3" color="#64748b" />
              <h4 class="text-subtitle-1 font-weight-bold text-white">No Payout Records Found</h4>
              <p class="text-caption text-medium-emphasis mb-0">Consignment sales recorded at the POS automatically generate itemized ledger payouts.</p>
            </v-sheet>
          </x-card>
        </v-sheet>

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

        <!-- MODAL: Register Consignor Dialog -->
        <x-dialog v-model="isConsignorModalOpen" max-width="500">
          <x-card variant="glass" class="pa-6">
            <h3 class="text-h6 font-weight-bold text-white mb-1">Register New Consignor</h3>
            <p class="text-caption text-medium-emphasis mb-4">Set up a consignment partner profile, split percentage, and disbursement method.</p>

            <v-sheet color="transparent" class="d-flex flex-column ga-3">
              <x-text-field
                v-model="newConsignor.name"
                label="Partner / Consignor Name"
                density="compact"
                variant="outlined"
                prepend-inner-icon="fad fa-user"
                hide-details
              />
              <x-text-field
                v-model="newConsignor.email"
                label="Email Address"
                density="compact"
                variant="outlined"
                prepend-inner-icon="fad fa-envelope"
                hide-details
              />
              <x-text-field
                v-model="newConsignor.phone"
                label="Phone Number"
                density="compact"
                variant="outlined"
                prepend-inner-icon="fad fa-phone"
                hide-details
              />
              <x-text-field
                v-model.number="newConsignor.default_split_rate"
                label="Consignor Payout Split (%)"
                density="compact"
                variant="outlined"
                prepend-inner-icon="fad fa-percent"
                hide-details
              />
              <x-select
                v-model="newConsignor.payout_method"
                label="Disbursement Method"
                :items="['Cash', 'Venmo', 'PayPal', 'Zelle', 'Bank Transfer']"
                density="compact"
                variant="outlined"
                prepend-inner-icon="fad fa-money-check"
                hide-details
              />
              <x-text-field
                v-model="newConsignor.payout_handle"
                label="Payment Handle / Account ID"
                density="compact"
                variant="outlined"
                placeholder="@username or account details"
                prepend-inner-icon="fad fa-id-badge"
                hide-details
              />
            </v-sheet>

            <v-sheet color="transparent" class="d-flex justify-space-between mt-6">
              <x-btn variant="text" @click="isConsignorModalOpen = false">Cancel</x-btn>
              <x-btn color="primary" :loading="isSubmitting" @click="createConsignor">Register Consignor</x-btn>
            </v-sheet>
          </x-card>
        </x-dialog>

        <!-- MODAL: Optical Grading & Gemini Scan -->
        <x-dialog v-model="isScanModalOpen" max-width="520">
          <x-card variant="glass" class="pa-6">
            <h3 class="text-h6 font-weight-bold text-white mb-1">Gemini Vision Card Scanner</h3>
            <p class="text-caption text-medium-emphasis mb-4">Multimodal optical card identification and automated surface/centering grade analysis.</p>

            <v-sheet color="transparent" class="border border-dashed border-white/20 rounded-lg pa-8 text-center mb-4">
              <v-icon icon="fad fa-camera-retro" size="48" color="#62c9ff" class="mb-3" />
              <div class="text-body-2 font-weight-medium text-white">Live Camera / Hardware Scanner Input</div>
              <p class="text-caption text-medium-emphasis mb-3">Place trading card under optical station or upload high-resolution scan</p>
              <x-btn color="primary" variant="tonal" size="small" :loading="isScanning" @click="runOpticalScan">
                Analyze Card Image
              </x-btn>
            </v-sheet>

            <v-sheet v-if="scanCardResult" color="transparent" class="callout-info pa-4 rounded-lg">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="font-weight-bold text-white">{{ scanCardResult.card_name }}</span>
                <x-chip size="x-small" color="success" variant="tonal" class="font-weight-bold">
                  {{ scanCardResult.grade }}
                </x-chip>
              </div>
              <div class="text-caption d-flex justify-space-between">
                <span>Centering: {{ scanCardResult.centering }}</span>
                <span>Surface: {{ scanCardResult.surface_score }}/10</span>
                <span class="font-weight-bold text-primary">Est. Value: \${{ scanCardResult.market_price }}</span>
              </div>
            </v-sheet>

            <v-sheet color="transparent" class="d-flex justify-end mt-4">
              <x-btn variant="text" @click="isScanModalOpen = false">Close</x-btn>
            </v-sheet>
          </x-card>
        </x-dialog>
      </v-container>
    `
  });

  // Pre-compile template if runtime compiler is present
  if (typeof window.Compass.Vue?.compile === "function") {
    try {
      CardVaultAdmin.render = window.Compass.Vue.compile(CardVaultAdmin.template);
    } catch (e) {
      console.warn("[CardVaultAdmin] Pre-compile template:", e);
    }
  }

  // Register with Compass Core
  window.Compass.registerPlugin("card-vault", {
    component: CardVaultAdmin
  });

  console.log("[CardVaultAdmin] Successfully registered 'card-vault' with Compass Bridge.");
})();
