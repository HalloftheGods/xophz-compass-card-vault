# Xophz Compass Card Vault

Offline-first Trade Desk, POS, optical grading, multi-consignor accounting, and WooCommerce product synchronization plugin for My Card Vault.

## Architecture & Capabilities

1. **Custom Relational Database Tables**:
   - `wp_xophz_vault_consignments`: Manages consignor profiles, split rates, payment channels, and WordPress user associations.
   - `wp_xophz_vault_payouts`: Stores transaction records, consignor payouts, dealer profit shares, and payout statuses (`unpaid`, `paid`). Enforces unique constraints on `sale_record_id` for idempotent offline syncing.

2. **WooCommerce Product Sync & Auto-Delist Protection**:
   - Synchronizes active inventory items into WooCommerce `product` CPTs.
   - Automatically delists sold products when physical card show transactions decrement stock to zero (`stock_status = outofstock`, `status = draft`, `visibility = hidden`).
   - Hooks into `woocommerce_order_status_completed` and `woocommerce_order_status_processing` to credit consignor payouts when cards sell online or via Bazaar POS.
   - Zero-Bloat Architecture: The 20,000+ card master reference catalog is held in lightweight client memory; WooCommerce products are created exclusively for physical active inventory.

3. **Role-Based WordPress Admin Dashboard**:
   - Dealers and Administrators: Access the full management portal (`/wp-admin/admin.php?page=xophz-compass-card-vault`), including storewide valuation, pending consignor liability, consignor management, and payout settlements.
   - Consignors (`card_vault_consignor` role): Access a scoped Consignor Dashboard displaying their active consigned items, sales history, and pending payout balances with wholesale acquisition costs strictly masked.

4. **Dev Reverse Proxy & Production Dist Loader**:
   - Development Mode (`WP_DEBUG` or `WP_ENV=development`): Reverse proxies the Vite dev server running on port 8092 with live Hot Module Replacement (HMR).
   - Production Mode: Loads compiled HTML and bundles from `public/dist/index.html`.
   - Injects `window.wpApiSettings` containing REST endpoint roots, nonces, and current user profile metadata.

5. **WP Connectors & Gemini Integration**:
   - Connects to Google Gemini API via official `wp_get_connectors()` framework with canonical ecosystem fallbacks.
   - Powers multimodal card identification (`POST /wp-json/xophz-card-vault/v1/scan-card`) and optical grading (`POST /wp-json/xophz-card-vault/v1/grade-card`).

6. **YouMeOS Spark Registry Integration**:
   - Registers webspark `card-vault` with category `pos` and color `#62c9ff`.
   - Exposes customizable route `/card-vault`.
