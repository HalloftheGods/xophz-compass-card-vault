# Changelog - Xophz Compass Card Vault

All notable changes to this WordPress plugin submodule will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-09-16]

### Changed
- **MySQL-Native Catalog Pricing & Price History Transition (`includes/class-card-vault-catalog-db.php`, `includes/class-card-vault-price-history.php`)**: Transitioned graded slab pricing (`psa9_price`, `psa10_price`, `bgs95_price`, `cgc10_price`, `pricing_source`) and historical price snapshots from legacy SQLite `cards.db` to WordPress MySQL InnoDB tables (`{$wpdb->prefix}card_vault_cards` and `{$wpdb->prefix}card_vault_price_history`).
- **Batch Pricing Lookup Engine (`Card_Vault_Catalog_DB::get_cards_pricing_batch`)**: Refactored batch pricing query to directly query the MySQL cards table using `$wpdb` with chunked prepared statements, supporting both direct card IDs and numeric TCGPlayer IDs.
- **PriceCharting CSV Ingestion (`Card_Vault_Catalog_Importer::ingest_pricecharting_csv`)**: Updated composite matching parser and slab pricing updates to operate natively against MySQL `$cards_table` and MySQL price history tables.
- **WP-CLI Prune History (`Card_Vault_Catalog_CLI::prune_history`)**: Updated pruning routine to execute directly on MySQL `$wpdb` without requiring SQLite PDO connections.

## [2026-09-15]

### Added
- **Referral Summary REST Endpoint (`includes/class-card-vault-api.php`)**: Registered `GET /referrals/summary` returning active authenticated user's referral code, share URL, total attributed conversions, and net commission earnings derived from the Bazaar transaction ledger and user metadata.

### Changed
- **License Checkout Referral Attribution (`includes/class-card-vault-api.php`)**: Updated `handle_license_checkout` to parse `referral_code`, resolve the referring WordPress user ID, determine active commission rate (default 20%), and forward attribution metadata into Stripe session payloads and fallback query strings.

### Fixed
- **Authentication 403 Cookie Check Bypass (`includes/class-card-vault-api.php`)**: Added `bypass_cookie_check_for_auth` filter on `rest_authentication_errors` (priority 999). Bypasses WordPress core's `rest_cookie_invalid_nonce` ("Cookie check failed") error on `/auth/login`, `/auth/me`, and `/auth/logout` endpoints when visitors or clients submit credentials with stale cookies or expired nonces, allowing credentials to be evaluated directly.

## [2026-09-14]

### Added
- **WP Admin Dealer HQ Crawler Queue Controls**: Added comprehensive crawler queue orchestration to `admin/partials/catalog-sync-display.php` matching the frontend app, including configurable Batch Size (5, 10, 20, 25, 50, all sets), Polite Throttle Delay / Buffer (5s, 15s, 30s, 60s, 120s, 300s), and Target Completion Duration (0.5 to 72 hours).
- **Manual Single-Step Action (`step_crawler_action`)**: Added `step_crawler_action` in `admin/class-card-vault-admin.php` and updated `process_next_step( true )` in `includes/class-card-vault-catalog-crawler.php`, allowing administrators to manually ingest exactly 1 pending set on demand without triggering continuous background cron loops.
- **Active Queue Manifest Inspector**: Added real-time queue manifest table in `admin/partials/catalog-sync-display.php` displaying pending, processing, completed, and failed sets with badges, category titles, and imported card counters.
- **Live Throttle Buffer & ETA Telemetry**: Added `eta_formatted` localization in `includes/class-card-vault-catalog-crawler.php` and buffer delay countdown display in `admin/partials/catalog-sync-display.php`.
- **Admin Feedback Notice Banners**: Added interactive dismissible alert banners in `admin/partials/dealer-portal-display.php` for `crawler_started`, `crawler_paused`, `crawler_stepped`, `crawler_reset`, and configuration updates.
- **MySQL Fast Path C & D Search Indexes**: Added Fast Path C (matching keyword with single card numbers like `Charizard 4` or promo codes without requiring a total set denominator) and Fast Path D (direct indexed lookups for standalone promo or set numbers like `SWSH020` or `TG01`) in `includes/class-card-vault-catalog-db.php`.
- **Subsite-Isolated MySQL Catalog Engine**: Refactored `includes/class-card-vault-catalog-db.php` from SQLite PDO to WordPress native MySQL (`$wpdb->prefix . 'card_vault_cards'`), establishing 100% compatibility with WPMU DEV managed hosting environments lacking `pdo_sqlite`. Features B-tree indexes on card lookups and native MySQL `FULLTEXT` indexing on `(name, clean_name, group_name)`.
- **Selective Sync Sets Table**: Added `{$wpdb->prefix}card_vault_sync_sets` table in `includes/class-card-vault-catalog-db.php` tracking per-set sync status, card counts, priorities, and enabled toggles.
- **Dealer HQ Catalog & Sync Dashboard**: Created `admin/partials/catalog-sync-display.php` and integrated `Catalog & Sync` tab in `admin/partials/dealer-portal-display.php` providing real-time telemetry (card count, table MB, engine status), crawler controls (Start, Pause, Resume, Reset), category prioritization chips, and set scope selector (Latest 10, 25, 50, or All sets).
- **Catalog Sync Ingestion REST Endpoints**: Registered `/catalog/sync/settings` (`GET` and `POST`) in `includes/class-card-vault-catalog-rest.php` allowing web apps to configure and retrieve subsite category/set ingestion preferences.
- **Admin Form Handlers**: Added `save_catalog_sync_settings`, `start_crawler_action`, `pause_crawler_action`, and `reset_crawler_action` in `admin/class-card-vault-admin.php`.

### Changed
- **TCGPlayer 1000w High-Res Resolution Normalization**: Added automatic URL rewriting in `includes/class-card-vault-catalog-importer.php` and `includes/class-card-vault-catalog-db.php` upgrading low-res `_200w.jpg` and `fit-in/200x200` to `_1000w.jpg` and `fit-in/1000x1000/`, providing crisp Retina image delivery without increasing database storage.
- **Crawler Manifest Filtering**: Updated `build_manifest()` in `includes/class-card-vault-catalog-crawler.php` to respect subsite configured category IDs, active set scope limits, and disabled set exclusions before queuing.
- **Batch Card Upsert Optimization**: Updated `import_group_from_tcgcsv()` in `includes/class-card-vault-catalog-importer.php` to use `Card_Vault_Catalog_DB::batch_upsert_cards()` via MySQL `INSERT ... ON DUPLICATE KEY UPDATE` with 200-card chunking.
- **WP-CLI Status Output**: Updated `wp card-vault status` in `includes/class-card-vault-catalog-cli.php` to report MySQL InnoDB storage metrics, table name, and card counts.
## [2026-09-14]

### Added
- **Dual-Track SQLite Valuation Engine**: Extended `includes/class-card-vault-catalog-db.php` with dedicated slab price columns (`psa9_price`, `psa10_price`, `bgs95_price`, `cgc10_price`, `pricing_source`) and automated schema migration checks for existing `cards.db` files.
- **Time-Series Price History System**: Created `includes/class-card-vault-price-history.php` providing single-responsibility management of the `card_price_history` SQLite table, batch transaction snapshots on set ingestion, portfolio historical valuation aggregation, and automated 90-day retention pruning.
- **PriceCharting CSV Ingestion**: Built composite matching parser in `includes/class-card-vault-catalog-importer.php` matching on set, card number, and variant tokens to ingest graded slab comps, accessible via WP-CLI `wp card-vault import-pricecharting` and REST `/catalog/upload-pricecharting`.
- **Historical Pricing REST Endpoints**: Registered `/catalog/cards/{id}/history` and `/catalog/portfolio/history` in `includes/class-card-vault-catalog-rest.php` returning daily time-series comp points for charting.
- **Dual-Mode Webhook & Action Scheduler Ingestion**: Updated `includes/class-card-vault-hookshot-bridge.php` to register `card_vault_catalog_sync` with Hookshot, asynchronously enqueueing tasks via Action Scheduler (`as_enqueue_async_action`) to avoid web request timeouts. Added a lightweight 6-hour Action Scheduler HTTP HEAD probe in `includes/class-card-vault-catalog-importer.php` as a self-hosted failsafe.

### Fixed
- **Gemini Model Upgrade and Transient 503 Failover**: Updated default vision/multimodal model in `includes/class-card-vault-gemini.php` from deprecated `gemini-2.5-flash` to `gemini-3.6-flash`. Added resilient failover chain to `gemini-3.5-flash` for automatic recovery during Google API 503 high-demand spikes, 429 rate limits, and 404 retired models. Added configurable model resolution via `card_vault_gemini_model` WordPress option and `GEMINI_MODEL` constant fallback.
- **Scan-Card Domain Envelope Resolution (`handle_scan_card`)**: Enhanced `handle_scan_card` in `includes/class-card-vault-api.php` to automatically query the SQLite database (`Card_Vault_Catalog_DB::search_cards`) using extracted card name and number. Attached full `matchedCard` domain object with verified market pricing, set metadata, and clean numbering, falling back to a structured Gemini envelope when not in SQLite. Ensures the frontend scanner queue and batch review modal immediately display the card without remaining stuck on "Identifying card...".
- **Scan-Card Text Hint Injection & Fallback Resolution (`handle_scan_card`)**: Enhanced `handle_scan_card` in `includes/class-card-vault-api.php` to accept optional `textHint` parameter. Injected user-supplied card context directly into Gemini's multimodal prompt, provided direct SQLite catalog lookup fallback using `textHint`, and added support for `image` as an alternate key to `imageBase64`.

## [2026-09-13]

### Added
- **Isolated Local WebP Image Cache Engine**: Created `includes/class-card-vault-image-cache.php` providing high-speed disk caching of TCG catalog card images converted to WebP in `wp-content/uploads/card-vault-cache/images/`. Fully compatible with WPMU DEV managed hosting by using native WordPress core APIs (`wp_get_image_editor`, `wp_upload_dir`, `wp_remote_get`) without shell exec dependencies. Prevents WP Media Library and database bloat by avoiding `wp_posts` attachments and thumbnail multiplication.
- **Card Image Cache REST Endpoints**: Registered `/catalog/cards/{id}/image`, `/catalog/cache/stats`, and `/catalog/cache/purge` in `includes/class-card-vault-catalog-rest.php` with 302 redirect support and disk cache telemetry.
- **Mathematical Category & Group Catalog Crawler**: Created `includes/class-card-vault-catalog-crawler.php` providing automated multi-category discovery across 94 TCG categories on tcgcsv.com, mathematical pacing calculating exact delay intervals based on remaining sets and target hours, persistent queue state machine, self-healing cron execution, and activation-triggered population.
- **Crawler REST Endpoints**: Registered `/catalog/categories` and `/catalog/crawler/*` (`status`, `start`, `pause`, `resume`, `step`, `reset`) in `includes/class-card-vault-catalog-rest.php` providing full queue diagnostics and interactive crawler controls.
- **WP-CLI Crawl Command Suite**: Added `wp card-vault crawl` and `wp card-vault categories` in `includes/class-card-vault-catalog-cli.php` supporting foreground daemon loops, mathematical pacing status reports, and set-by-set telemetry.
- **SQLite Master Catalog Engine**: Created `includes/class-card-vault-catalog-db.php` providing high-performance local SQLite storage (`cards.db`) with Write-Ahead Logging (WAL mode), 16MB page cache, B-Tree indexes, and FTS5 full-text search with automatic sync triggers.
- **Smart Card Number Normalization**: Created `includes/class-card-vault-number-normalizer.php` decomposing card numbers (e.g. `004/102`, `199/165`, `TG01/TG30`, `OP05-001`), precomputing full-text search variant vectors, and sanitizing user queries to eliminate FTS5 syntax errors.
- **TCG CSV Ingestion & Snapshot Sync**: Created `includes/class-card-vault-catalog-importer.php` supporting dual-mode operations: Hub Mode (ingesting daily CSV dumps from tcgcsv.com and building compressed snapshots) and BlackBox Client Mode (atomic download and zero-downtime database hydration).
- **Automated 6-Hour Catalog Check Cron**: Registered `six_hours` WP-Cron interval (21600 seconds) in `includes/class-card-vault-catalog-importer.php` running `card_vault_catalog_check_cron` to verify Hub version manifests and auto-apply snapshots when updates are detected.
- **Single Card SKU & Code 128 Barcodes**: Created `includes/class-card-vault-sku-generator.php` generating deterministic `CV-*` single inventory SKUs and rendering pure SVG Code 128 barcodes for 2.25" × 1.25" thermal toploader labels.
- **Hookshot Webhook Automation Bridge**: Created `includes/class-card-vault-hookshot-bridge.php` listening for `catalog.updated` events from Central Hub (`cardvault.worldwidewebwork.com`) to trigger background database hydration.
- **Catalog & Barcode REST Endpoints**: Registered `/wp-json/card-vault/v1/catalog/search`, `/catalog/cards/{id}`, `/catalog/barcode/{code}`, and `/catalog/status` secured via Gatekeeper API keys (`read:cards` scope) with support for query param `?check=1` to query Hub version.
- **WP-CLI Management Suite**: Created `includes/class-card-vault-catalog-cli.php` providing `wp card-vault sync_group`, `wp card-vault sync_all`, `wp card-vault sync_hub`, `wp card-vault search`, `wp card-vault barcode`, `wp card-vault status`, `wp card-vault check_updates`, and `wp card-vault export_snapshot`.
- **Batch Pokémon Set Sync & Rate-Limiting**: Added `batch_sync_groups()` and `get_available_groups()` in `includes/class-card-vault-catalog-importer.php` ingesting Pokémon sets from tcgcsv.com with configurable throttle delays and resume support.
- **Empty Query Browsing & Pagination**: Enhanced `search_cards()` and added `count_cards()` in `includes/class-card-vault-catalog-db.php` supporting offset pagination and catalog browsing when search query is empty.
- **Group Statistics & Admin Gating**: Added `get_synced_groups()` in `includes/class-card-vault-catalog-db.php` deriving set sync timestamps and card counts, and restricted `/catalog/sync` to administrator capabilities (`manage_options`).

### Changed
- **Compiled Frontend Assets**: Rebuilt `my-card-vault` production bundle and updated assets in `public/dist/` with Portfolio QR page, buyer portal, and fixed x-atoms global typing.
- **Bazaar POS Barcode Resolution**: Enhanced `lookup_barcode` in `includes/class-card-vault-products.php` to resolve both manufacturer UPCs (sealed boxes/packs) from the SQLite catalog and `CV-*` single card SKUs from vault inventory in under 5ms.
- **Thermal Label Tag Preview**: Integrated Code 128 barcode and single card SKU into the printable thermal sticker studio in `apps/my-card-vault/components/organisms/AIGradingModal.tsx`.

## [2026-09-12]

### Added
- **Centralized Compass Auth Integration**: Updated `/auth/login` in `includes/class-card-vault-api.php` to integrate with `Xophz_Compass_Auth_API`, providing multi-representation password verification, captcha stripping, and accurate error pass-through.
- **WordPress Profile & WooCommerce Account Add to Collection Buttons**: Added prominent "+ Add to Collection" and "Import More (CSV)" action buttons to both the WordPress user profile collection view (`wp-admin/profile.php`) and the WooCommerce My Account collection page (`/my-account/card-vault`), routing users directly into the card intake and CSV import workflow.
- **In-App WordPress Authentication REST Endpoints**: Registered `/auth/login`, `/auth/logout`, and `/auth/me` in `includes/class-card-vault-api.php` utilizing `wp_authenticate_username_password()`, `wp_set_current_user()`, and `wp_set_auth_cookie()` with direct username or email fallback, cleanly bypassing Turnstile CAPTCHA on `wp-login.php`.
- **WordPress Admin Profile Collection Display**: Added `show_user_profile` and `edit_user_profile` hooks in `admin/class-card-vault-admin.php` rendering a dedicated dark-mode Card Vault Collection table with total card count, estimated raw portfolio value, and a 24-card preview grid on `wp-admin/profile.php`.
- **WooCommerce My Account Vault Endpoint**: Registered `/my-account/card-vault` endpoint and menu tab in `includes/class-card-vault-community.php` enabling logged-in collectors to review their synced cloud inventory directly within WooCommerce My Account.

### Changed
- **Compiled Frontend Assets**: Rebuilt frontend application bundling vendor interest checklist modal, checklist search filter, card interested badges, target offer cards preview, quick percentage offer buttons, and showcase QR code sharing modal into `public/dist/`.
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
