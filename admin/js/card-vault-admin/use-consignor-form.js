/**
 * Register Consignor dialog: form state and createConsignor().
 * createConsignor() requires a name, posts the form to /consignors, and on success closes the
 * dialog, resets the form and reloads Dealer HQ data through refresh(). Failures show a toast.
 */
import { cardVaultRequest } from "./card-vault-rest.js";

const { ref } = window.Compass.Vue;

const blankConsignor = () => ({
  name: "",
  email: "",
  phone: "",
  default_split_rate: 85,
  payout_method: "Cash",
  payout_handle: "",
  notes: ""
});

export const useConsignorForm = (props, notify, refresh) => {
  const isConsignorModalOpen = ref(false);
  const isSubmitting = ref(false);
  const newConsignor = ref(blankConsignor());

  const createConsignor = async () => {
    if (!newConsignor.value.name) {
      notify("Please provide a consignor name.");
      return;
    }

    isSubmitting.value = true;
    try {
      const res = await cardVaultRequest(props.context, "POST", "/consignors", newConsignor.value);

      if (res.success) {
        notify(`Consignor "${newConsignor.value.name}" registered successfully.`);
        isConsignorModalOpen.value = false;
        newConsignor.value = blankConsignor();
        await refresh();
      } else {
        notify(res.message || "Failed to register consignor.");
      }
    } catch (err) {
      notify(`Could not register consignor: ${err.message}`);
    } finally {
      isSubmitting.value = false;
    }
  };

  return { isConsignorModalOpen, isSubmitting, newConsignor, createConsignor };
};
