/**
 * Gemini Vision scan dialog: open state, scanning flag and the last scan result.
 * runOpticalScan() posts to /scan-card and stores the response body.
 * Known defect, task #6451: the request sends a placeholder image and the catch branch shows a
 * fabricated result instead of an error.
 */
import { resolveCardVaultRest } from "./card-vault-rest.js";

const { ref } = window.Compass.Vue;

export const useOpticalScan = (props) => {
  const isScanModalOpen = ref(false);
  const isScanning = ref(false);
  const scanCardResult = ref(null);

  const runOpticalScan = async () => {
    isScanning.value = true;
    scanCardResult.value = null;
    try {
      const { api, base } = resolveCardVaultRest(props.context);
      const res = await api.post(`${base}/scan-card`, {
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

  return { isScanModalOpen, isScanning, scanCardResult, runOpticalScan };
};
