/**
 * CardVaultAdmin: the Compass admin view for Dealer HQ, Consignors, Payouts Ledger and Settings.
 * setup() composes the domain composables in this folder; the template lives in
 * card-vault-admin.template.js. Loaded on demand by admin/js/card-vault-admin.js.
 */
import { cardVaultAdminTemplate } from "./card-vault-admin.template.js";
import { useCardVaultToast } from "./use-card-vault-toast.js";
import { useDealerData } from "./use-dealer-data.js";
import { formatUsd } from "./format-usd.js";
import { usePayoutLedger } from "./use-payout-ledger.js";
import { useConsignorForm } from "./use-consignor-form.js";
import { useOpticalScan } from "./use-optical-scan.js";

const { defineComponent, ref, computed, onMounted, watch } = window.Compass.Vue;

// Local display defaults only: nothing reads or saves these to the server yet.
const defaultTradeSettings = () => ({
  cashBuyoutRate: 70,
  tradeBuyoutRate: 80,
  autoDelistSync: true
});

export const CardVaultAdmin = defineComponent({
  name: "CardVaultAdmin",
  props: {
    context: {
      type: Object,
      default: () => ({})
    }
  },
  setup(props) {
    const activeTab = ref(props.context?.subPath || "overview");
    const currentSection = computed(() => props.context?.subPath || "overview");
    const tradeSettings = ref(defaultTradeSettings());

    const { notify, ...toast } = useCardVaultToast();
    const dealer = useDealerData(props, notify);
    const hasConsignors = computed(() => dealer.consignors.value.length > 0);
    const ledger = usePayoutLedger(props, dealer.payouts, notify, dealer.fetchAllData);
    const consignorForm = useConsignorForm(props, notify, dealer.fetchAllData);
    const scan = useOpticalScan(props, notify);

    const launchPosApp = () => {
      const slug = props.context?.manifest?.slug || "card-vault";
      window.open(`/${slug}`, "_blank");
    };

    watch(
      () => props.context?.subPath,
      (newPath) => {
        activeTab.value = newPath || "overview";
      }
    );

    onMounted(() => {
      void dealer.fetchAllData();
    });

    return {
      activeTab,
      currentSection,
      tradeSettings,
      hasConsignors,
      launchPosApp,
      formatUsd,
      ...toast,
      ...dealer,
      ...ledger,
      ...consignorForm,
      ...scan
    };
  },
  template: cardVaultAdminTemplate
});
