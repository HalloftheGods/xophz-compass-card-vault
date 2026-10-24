/**
 * Register Consignor dialog: form state and createConsignor().
 * createConsignor() requires a name, posts the form to /consignors, and on success closes the
 * dialog, resets the form and reloads Dealer HQ data through refresh(). Failures show a toast.
 */
import { resolveCardVaultRest } from "./card-vault-rest.js";

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
      const { api, base } = resolveCardVaultRest(props.context);
      const res = await api.post(`${base}/consignors`, newConsignor.value);

      if (res.data?.success) {
        notify(`Consignor "${newConsignor.value.name}" registered successfully.`);
        isConsignorModalOpen.value = false;
        newConsignor.value = blankConsignor();
        await refresh();
      } else {
        notify(res.data?.message || "Failed to register consignor.");
      }
    } catch (err) {
      notify("Error communicating with Card Vault service.");
    } finally {
      isSubmitting.value = false;
    }
  };

  return { isConsignorModalOpen, isSubmitting, newConsignor, createConsignor };
};
