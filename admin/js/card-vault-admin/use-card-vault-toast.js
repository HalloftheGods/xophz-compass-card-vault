/**
 * Dealer HQ snackbar state: notify(msg) sets the message and opens the snackbar.
 */
const { ref } = window.Compass.Vue;

export const useCardVaultToast = () => {
  const toastMessage = ref("");
  const showToast = ref(false);

  const notify = (msg) => {
    toastMessage.value = msg;
    showToast.value = true;
  };

  return { toastMessage, showToast, notify };
};
