# System Architecture — Zaylotix POS/ERP SaaS

Part of the full technical audit (section 16, deliverable #1). Written from direct, continuous engineering experience on this codebase, not a cold read — every claim below is backed by a specific file/pattern that can be grep'd to confirm.

## 1. Stack

- **Backend**: Laravel 11 (PHP 8.2+), MySQL 8 (production) / SQLite `:memory:` (tests, `phpunit.xml`).
- **Frontend**: Vue 3 + Inertia.js (server-driven SPA — no separate REST/GraphQL API layer for the app itself; `resources/js/Pages/**/*.vue` map 1:1 to `Inertia::render()` calls).
- **Build**: Vite (`vite.config.js`), manifest-based asset versioning (`public/build/manifest.json`), a client-side version check (`AppLayout.vue`) that detects a stale build after deploy and prompts reload.
- **Mobile**: Android TWA (Trusted Web Activity) wrapping the live site (`android/`) — loads `pos.zaylotix.com` directly, so a frontend deploy needs **no APK rebuild**; only icon/theme/signing changes do. A separate tiny native app (`printer-helper/`) handles Bluetooth thermal-label printing via Android's Share target, since Web Bluetooth can't reach the XP-365B's classic SPP profile.
- **Hosting**: Hostinger Cloud Professional (shared-tenancy VM, 201.67.161.68), both `zaylotix.com` (marketing/signup) and `pos.zaylotix.com` (the app) on the same account. No staging environment, no container orchestration, no load balancer — a single PHP-FPM + Nginx + MySQL stack.
- **Constraints baked into the code**: `exec()`/`shell_exec()`/`proc_open()` are disabled on this host. Every place that would normally shell out works around it — `BackupDatabase` uses `ifsnop/mysqldump-php` (pure-PHP dump) instead of the `mysqldump` binary; there is no long-running `queue:work` daemon, so queued jobs ride the same per-minute cron tick that runs `schedule:run` (see `routes/console.php`'s own comments on this).

## 2. Multi-tenancy model

- **Tenant = `Shop` row.** Every tenant-owned table has a `shop_id` column and uses the `BelongsToTenant` trait, which registers a global Eloquent scope (`TenantScope`) filtering every query to `Tenancy::id()`.
- **`App\Support\Tenancy`** is the single source of truth for "which shop is active right now" — backed by the authenticated user's `shop_id` by default, overridable per-request for a branch switch (`BranchController::switch`) or an admin impersonation session. `Tenancy::set()`/`Tenancy::clear()`/`Tenancy::id()`/`Tenancy::shop()` are called from middleware early in the request lifecycle so every controller downstream sees the correct tenant without passing it explicitly.
- **Branches**: a `Shop.parent_shop_id` self-reference turns a shop into a branch of a main shop. Branches are otherwise ordinary, fully independent tenant rows (own products, own stock, own sales) — NOT a shared-row model. A small number of concerns deliberately resolve to the parent regardless of which branch you're looking at (`Shop::invoiceCounterShop()`, `articleSettingsShop()`, `labelHeader()` — always; `subscriptionShop()` — opt-in via `billing_follows_parent`, added 2026-11-20 for Baby Park's combined-billing arrangement). Everything else (stock, sales, customers, staff) is genuinely per-branch.
- **Known, audited escape hatches from the tenant scope** (each intentional, each reviewed this session): `withoutGlobalScopes()` is used wherever code must legitimately read across the active tenant — e.g. `Tenancy::businessShopIds()`-based cross-branch reports, `ReturnController`'s cross-branch exchange lookup (the exact thing that was broken and fixed for Baby Park on 2026-11-13), public unauthenticated routes with no tenant context at all (`CatalogController@show`, `RatingController`). See `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md` for the full isolation-specific audit of this boundary.
- **Shared-schema, not shared-database**: all tenants live in one MySQL database/schema, isolated by row (`shop_id`), not by separate database or separate schema per tenant. This is the single biggest scalability lever available if row-count ever becomes a real bottleneck (see `SCALABILITY_AUDIT.md`).

## 3. Request lifecycle (web/app side)

`routes/web.php` → tenant-aware middleware stack (`auth`, a custom `EnsureShopUser`, `CheckSubscriptionActive`, `perm:*`/`feature:*` gates) → `App\Http\Controllers\App\*Controller` → Eloquent models (tenant-scoped) → `Inertia::render('Page/Name', [...])` → the matching `resources/js/Pages/Page/Name.vue`.

- **`CheckSubscriptionActive`**: the per-request gate enforcing `Shop::isActive()` (status + subscription_expiry, now resolved through `subscriptionShop()` for a combined-billing branch). Admin impersonation always bypasses it (so support can open an expired shop to help it). A background poll (`X-Inertia-Poll` header) gets a soft "try again" 401 instead of a hard logout, to avoid destroying a live session over a transient DB hiccup.
- **Money-moving writes** (checkout, returns, purchases, payments, stock transfers, subscription renewals) all follow the same shape: `DB::transaction()` wrapping a `lockForUpdate()`'d read of the row(s) being mutated, so two near-simultaneous requests against the same shop/product/customer serialize instead of racing. `PosController::performCheckout()` is the canonical example and the single choke point every checkout caller (the HTTP route, the payment-gateway webhook, the offline-sync replay) goes through — deliberately consolidated there in this session's tenant-isolation audit so a guard added to it (e.g. the warehouse/head-office checkout block) can never be bypassed by one specific caller.
- **Idempotency**: `client_uuid` on `Sale` makes a replayed checkout (offline-sync retry, a lost HTTP response) return the original sale instead of double-selling/double-decrementing stock.

## 4. Admin (platform) side

A fully separate guard (`admin`, `App\Models\Admin`, `App\Http\Controllers\Admin\*`) and layout from the tenant app — Khaled's own platform-operator console. Shop CRUD, feature-flag grants per shop, subscription/billing (manual payment recording, now resolving through `subscriptionShop()`), impersonation (`AdminActivity::log()`'d), backups/restore, ambassador tracking, site-wide analytics scoped to `account_type = 'paid'` (so demo/internal shops never inflate revenue figures). Not reachable by any tenant user regardless of role.

## 5. Feature-flag / package system

- **`Feature`** rows (key, label, category) ↔ `Shop` via a pivot (`shop_features`) — `Shop::featureKeys()`/`hasFeature()`. Checked server-side via a `feature:<key>` route middleware AND re-checked in the UI (sidebar/nav hide, not just a backend 403) so a disabled feature genuinely disappears, not just gets hidden with an unprotected backend underneath.
- **Permissions** (`config/staff_permissions.php`) are a second, orthogonal axis — a shop can have a feature enabled but restrict which staff role can use it (`perm:<key>` middleware). Owner always has every permission implicitly.
- Granting a feature to a main shop auto-propagates to its branches (one-directional — removing it from the main shop never auto-removes it from a branch that may still need it); a branch's own feature set stays independently editable after that initial copy.

## 6. Background/scheduled work

No queue daemon (host constraint). Everything scheduled runs via `routes/console.php`'s `Schedule::call(...)` entries, fired by the one per-minute cron hitting `artisan schedule:run`: nightly DB backup (02:00), subscription expiry sweep, payment-due reminders, low-stock/expiry/warranty alerts, daily owner summary, installment late fees. `GenerateShopBackupJob` (owner-facing self-service data export) is the one real queued job, also riding this same cron tick rather than a live worker.

## 7. Printing / hardware integration

Two independent print paths, both client-driven (no server-side rendering of labels/receipts): browser `window.print()` for classic receipt printers, and a companion Android app (`printer-helper/`) for Bluetooth thermal label printers (XP-365B) using Android's native Share target — `html2canvas` snapshots the exact on-screen label DOM, resized to the printer's real dot dimensions, and hands it to the helper app via `navigator.share()`. `ReceiptMemo.vue` is the single source of truth for memo content (both print and WhatsApp-share read it) — notably **always renders in English** regardless of the shop's UI language (an explicit, app-wide decision, 2026-11-20).

## 8. What this audit's other sections cover in more depth

This document is the map; the following sibling deliverables are the actual test results for each area: `FEATURE_AUDIT.md`, `INVENTORY_STOCK_AUDIT.md`, `DATABASE_AUDIT.md`, `SECURITY_AUDIT.md`, `PERFORMANCE_AUDIT.md`, `SCALABILITY_AUDIT.md`, `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md` (already completed, 2026-11-20).
