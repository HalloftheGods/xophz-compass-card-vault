# Changelog - Xophz Compass Card Vault

All notable changes to this WordPress plugin submodule will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
