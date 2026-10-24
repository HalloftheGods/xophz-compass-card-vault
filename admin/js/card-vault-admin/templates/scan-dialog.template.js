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
`;
