# Changelog - Xophz Compass Card Vault

All notable changes to this WordPress plugin submodule will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-09-12]

### Added
- **In-App WordPress Authentication REST Endpoints**: Registered `/auth/login`, `/auth/logout`, and `/auth/me` in `includes/class-card-vault-api.php` utilizing `wp_authenticate_username_password()`, `wp_set_current_user()`, and `wp_set_auth_cookie()` with direct username or email fallback, cleanly bypassing Turnstile CAPTCHA on `wp-login.php`.
- **WordPress Admin Profile Collection Display**: Added `show_user_profile` and `edit_user_profile` hooks in `admin/class-card-vault-admin.php` rendering a dedicated dark-mode Card Vault Collection table with total card count, estimated raw portfolio value, and a 24-card preview grid on `wp-admin/profile.php`.
- **WooCommerce My Account Vault Endpoint**: Registered `/my-account/card-vault` endpoint and menu tab in `includes/class-card-vault-community.php` enabling logged-in collectors to review their synced cloud inventory directly within WooCommerce My Account.

### Changed
- **Compiled Frontend Assets**: Rebuilt frontend application bundling showcase QR code sharing modal, sticky header quick-share triggers, return navigation, and collection pagination into `public/dist/`.
- **Frontend Dev Proxy API Settings Version Propagation**: Injected `version` and `versionString` matching `XOPHZ_COMPASS_CARD_VAULT_VERSION` (`26.9.12-1209`) in `filter_api_settings` within `public/class-card-vault-public.php` to guarantee active runtime plugin version is accessible to frontend consumers.
- **Collector Show Bid Ingestion & Fallbacks**: Enhanced `submit_show_bid` in `includes/class-card-vault-community.php` to resolve target collectors via explicit `collectorUserId`, logged-in session, or primary administrator fallback, with safe default booth/contact fallbacks.
- **Collector Items Timestamp Formatting**: Enhanced `get_collector_items` to output ISO-formatted `dateAdded` and `lastModified` strings directly from MySQL timestamps.
- **Showcase Demo Fallback**: Enhanced `get_public_showcase` in `includes/class-card-vault-community.php` to resolve primary collector records when scanning demo or unauthenticated links.

## [2026-09-08]

### Added
- **Stripe Payments Bridge & WP Connectors**: Created `includes/class-card-vault-stripe.php` retrieving `stripe_secret_key` and `stripe_publishable_key` dynamically from `wp_get_connectors()` to generate instant Stripe Checkout Sessions and QR pay links for card shows and shop counters.
- **Bazaar-Compatible Product Backend**: Created `includes/class-card-vault-products.php` providing WooCommerce product CRUD, atomic stock updates, and global barcode resolution (Local Inventory, TCG Master Catalog & UPCitemdb) for sealed boxes, packs, supplies, and singles.
- **REST Endpoints for POS & Products**: Registered routes in `includes/class-card-vault-api.php`:
  - `GET /pos/config`: Returns payment configuration, publishable key, and currency symbol.
  - `POST /pos/checkout`: Generates POS orders and Stripe Checkout Sessions with QR payloads.
  - `POST /pos/verify-payment`: Reconciles payment sessions and marks WooCommerce orders complete.
  - `GET /products` and `POST /products`: Manages shop products with Bazaar schema compatibility.
  - `POST /products/stock`: Atomic stock quantity adjustments (set, add, subtract).
  - `POST /products/lookup-barcode`: Universal barcode query for card accessories and sealed inventory.

### Fixed
- Integration: Ensured Card Vault is discovered and registered with COMPASS Starship dashboard and Universal Plugin Bridge.

## [2026-09-05]

### Added
- **Community Vault & Consignment Tables**: Database migrations in `includes/class-card-vault-activator.php` creating `wp_xophz_vault_collector_items`, `wp_xophz_vault_intake_batches`, `wp_xophz_vault_intake_items`, and `wp_xophz_vault_show_bids`.
- **Card Vault Collector Role**: Registered `card_vault_collector` role granting `view_collector_vault` capability.
- **Community & Intake Engine**: Created `includes/class-card-vault-community.php` managing community registration options, collector portfolio sync, 4-stage consignment intake batch lifecycle, and card show vendor bids.
- **REST Endpoints for Community & Shows**: Added endpoints in `includes/class-card-vault-api.php`:
  - `GET /collector/collection` and `POST /collector/collection/sync` for collector cloud portfolio sync.
  - `POST /intake/submit` and `GET /intake/batches` for consignment drop-off submissions.
  - `POST /intake/appraise` and `POST /intake/accept` for Trade Desk condition verification and WooCommerce auto-sync.
  - `GET /showcase/:slug` for public card show vendor showcases.
  - `POST /showcase/:slug/bid` for guest vendor cash/trade offer submissions.
  - `GET /collector/bids` and `POST /collector/bids/:id/action` for collector offer reviews.
  - `GET /settings/community` and `POST /settings/community` for community vault policies.
- **Admin Community Settings**: Added Community Vaults & Card Shows configuration panel to `admin/class-card-vault-admin.php`.
- **Role Detection Filter**: Enhanced `public/class-card-vault-public.php` to inject role (`dealer`, `consignor`, `collector`, `guest`) and community settings into `window.wpApiSettings`.
