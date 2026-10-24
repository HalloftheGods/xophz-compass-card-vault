/**
 * Card Vault Dealer HQ template partial: Gemini Vision card scanner dialog.
 * Composed into the CardVaultAdmin template; bindings come from its setup().
 */
export const scanDialogTemplate = `
        <!-- MODAL: Optical Grading & Gemini Scan -->
        <x-dialog v-model="isScanModalOpen" max-width="520">
          <x-card variant="glass" class="pa-6">
            <h3 class="text-h6 font-weight-bold text-white mb-1">Gemini Vision Card Scanner</h3>
            <p class="text-caption text-medium-emphasis mb-4">Multimodal optical card identification and automated surface/centering grade analysis.</p>

            <v-sheet color="transparent" class="border border-dashed border-white/20 rounded-lg pa-8 text-center mb-4">
              <v-icon icon="fad fa-camera-retro" size="48" color="#62c9ff" class="mb-3" />
              <div class="text-body-2 font-weight-medium text-white">Live Camera / Hardware Scanner Input</div>
              <p class="text-caption text-medium-emphasis mb-3">Choose a photo or scan of the card to identify it</p>
              <v-file-input v-model="scanFile" accept="image/*" label="Card image" density="compact" variant="outlined" hide-details class="mb-3" />
              <x-btn color="primary" variant="tonal" size="small" :loading="isScanning" @click="runOpticalScan">
                Analyze Card Image
              </x-btn>
            </v-sheet>

            <v-sheet v-if="scanCardResult" color="transparent" class="callout-info pa-4 rounded-lg">
              <div class="d-flex justify-space-between align-center mb-2">
                <span class="font-weight-bold text-white">{{ scanCardResult.cardName || 'Unidentified card' }}</span>
                <x-chip v-if="scanCardResult.estimatedCondition" size="x-small" color="info" variant="tonal" class="font-weight-bold">
                  {{ scanCardResult.estimatedCondition }}
                </x-chip>
              </div>
              <div class="text-caption d-flex justify-space-between">
                <span>Set: {{ scanCardResult.setName || 'Unknown' }}</span>
                <span v-if="scanCardResult.matchedCard?.pricing?.rawMarketPrice" class="font-weight-bold text-primary">
                  Market: {{ formatUsd(scanCardResult.matchedCard.pricing.rawMarketPrice) }}
                </span>
                <span v-else class="text-medium-emphasis">No catalog price</span>
              </div>
              <div v-if="scanCardResult.visualNotes" class="text-caption text-medium-emphasis mt-2">{{ scanCardResult.visualNotes }}</div>
            </v-sheet>

            <v-sheet color="transparent" class="d-flex justify-end mt-4">
              <x-btn variant="text" @click="isScanModalOpen = false">Close</x-btn>
            </v-sheet>
          </x-card>
        </x-dialog>
`;
