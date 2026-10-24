/**
 * Gemini Vision scan dialog: open state, the chosen image file, scanning flag and the last scan result.
 * runOpticalScan() reads the chosen image, posts it (imageBase64 and mimeType) to /scan-card and stores
 * the response body. With no image chosen, or when the request fails, it stores no result and shows a
 * toast via notify; it never shows a placeholder result. The result is the model's identification,
 * not a verified grade, and matchedCard.pricing is zero when the catalog has no price.
 */
import { cardVaultRequest } from "./card-vault-rest.js";

const { ref } = window.Compass.Vue;

// v-file-input may hold a File or an array of Files depending on its version.
const firstFile = (value) => (Array.isArray(value) ? value[0] : value) || null;

const readAsDataUrl = (file) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error("Could not read the image file."));
    reader.readAsDataURL(file);
  });

export const useOpticalScan = (props, notify) => {
  const isScanModalOpen = ref(false);
  const isScanning = ref(false);
  const scanCardResult = ref(null);
  const scanFile = ref(null);

  const runOpticalScan = async () => {
    const file = firstFile(scanFile.value);
    scanCardResult.value = null;
    if (!file) {
      notify("Choose a card image to scan first.");
      return;
    }

    isScanning.value = true;
    try {
      const imageBase64 = await readAsDataUrl(file);
      const mimeType = file.type || "image/jpeg";
      scanCardResult.value = await cardVaultRequest(props.context, "POST", "/scan-card", {
        imageBase64,
        mimeType
      });
    } catch (err) {
      notify(`Card scan failed: ${err.message}`);
    } finally {
      isScanning.value = false;
    }
  };

  return { isScanModalOpen, isScanning, scanCardResult, scanFile, runOpticalScan };
};
