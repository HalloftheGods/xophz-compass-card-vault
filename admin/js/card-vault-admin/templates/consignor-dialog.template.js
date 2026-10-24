/**
 * Card Vault Dealer HQ template partial: Register Consignor dialog.
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const consignorDialogTemplate = `
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
`;
