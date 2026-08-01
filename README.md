# Zaylotix POS — POS, Inventory & Accounting SaaS

A multi-tenant point-of-sale, inventory and accounting platform for small
retail shops, built with Laravel 12 + Inertia.js (Vue 3). Ported from a
single-file HTML prototype into a real, database-backed, multi-tenant
application with a separate admin (SaaS owner) panel.

**A [Zaylotix](https://zaylotix.com/) product.** Owner: **Khaled Bin Islam**
(https://khaledbinislam.com/, 01979894356) — kept in the footer/login of both
the shop app and the admin panel. Demo flagship shop: **Khaled Enterprise**.

---

## Architecture at a glance

### Multi-tenancy: shared tables + enforced row-level scoping

Every shop ("tenant") shares the same tables (`products`, `customers`,
`sales`, …), each carrying a `shop_id` column. **This is the standard,
crash-resistant approach** — one physical table per tenant does not scale
past a few dozen tenants and turns every migration into a fan-out operation
across N schemas. Instead, isolation is enforced in code, in two independent
layers so a bug in one doesn't mean a data leak:

1. **`BelongsToTenant` trait** (`app/Models/Concerns/BelongsToTenant.php`)
   — added to every tenant-owned model. It registers a global Eloquent scope
   (`TenantScope`) that filters every query to `shop_id = current tenant`,
   and a `creating` observer that auto-stamps `shop_id` on every new row.
   **If no tenant can be resolved (e.g. an admin-guard request, or a stray
   console command), the scope filters to `shop_id = 0` — i.e. nothing —
   rather than silently returning every shop's data.** This is deliberate:
   the safe failure mode is "see nothing", never "see everything".
2. **Policies** (`app/Policies/*`) — a second check on top of the scope,
   comparing `$user->shop_id === $model->shop_id` on `view`/`update`/`delete`.
   Even if a route-model-binding ever bypassed the global scope, the policy
   still refuses cross-tenant access.

The current tenant is resolved by `App\Support\Tenancy::id()`, which checks
(in order): an explicit override bound into the container (used by admin
read-only "view this shop" pages and by tests), then the logged-in shop
user's own `shop_id`. Admin-guard requests never resolve a tenant, so admin
queries are never accidentally scoped to (or leaking) shop data.

`tests/Feature/TenantIsolationTest.php` proves: a shop user only ever sees
their own products/customers/sales; a PUT to another shop's product 404s;
new records are auto-stamped with the correct `shop_id`; and an unresolved
tenant returns zero rows rather than all of them.

### Two separate apps, two separate guards

- **Shop app** (`/app/*`, `/login`) — the POS itself. Auth guard `web`,
  provider `users`. Gated by the `shop` middleware (must be logged in,
  binds the tenant) and `subscription` middleware (shop must be active and
  not past its subscription expiry — otherwise the session is killed and the
  user is bounced to login with a "subscription expired" message).
- **Admin app** (`/admin/*`) — the SaaS owner's panel. Auth guard `admin`,
  provider `admins`, completely separate session/table from shop users.
  `AppServiceProvider::boot()` registers a `Gate::before` that grants a
  logged-in admin universal access to any policy check — but only when the
  `admin` guard itself is authenticated, never inferred from the `web` guard.

`tests/Feature/AuthSeparationTest.php` proves the guards can't cross —
logging in as a shop user never authenticates the admin guard and vice
versa, and a shop user hitting `/admin/*` is bounced to the admin login.

### Crash-safety: transactions + row locking

- All money is `decimal(12,2)` — never float.
- **Checkout** (`App\Http\Controllers\App\PosController::checkout`) wraps
  the entire sale in one DB transaction: it locks the shop row and every
  line's product row (`lockForUpdate()`, in a consistent ascending-id order
  to avoid deadlocks under concurrent checkouts), verifies stock, decrements
  it, snapshots price/cost onto `sale_items`, resolves/creates the customer,
  updates the customer ledger, and bumps the shop's cash/bank balance and
  invoice counter — all in one commit. If anything fails partway (e.g.
  insufficient stock on line 3 of 5), the whole transaction rolls back: no
  half-decremented stock, no orphaned sale.
- Every other money-moving action (collect payment, purchase, expense,
  damage, return, stock count) is similarly wrapped in a transaction with
  `lockForUpdate()` on the rows it mutates.
- Products/customers are **soft-deleted**, never hard-deleted, so historical
  sales always resolve correctly even after a product is "removed".

`tests/Feature/CheckoutTransactionTest.php` proves: stock decrements
correctly; an insufficient-stock checkout leaves stock, sales, and cash
completely untouched; credit sales create/update the customer ledger; and
concurrent-looking rapid checkouts get strictly unique sequential invoice
numbers (guaranteed by the locked shop-row read-increment-write).

### Subscriptions (manual billing, admin-managed)

Admin creates a shop with a plan (`trial`/`monthly`/`yearly`), a
`subscription_start`/`subscription_expiry`, and records payments manually in
`subscription_payments` (who paid, how much, which month, method, next due
date) — no payment gateway integration, as requested. Recording a payment
with a `next_due` date immediately extends the shop's expiry and reactivates
it. A daily scheduled command, `byapari:expire-subscriptions`, deactivates
any shop whose expiry has passed — it only ever expires, never reactivates,
so it can't undo a manual suspension for reasons unrelated to the date.
`tests/Feature/SubscriptionExpiryTest.php` covers this end to end.

### Business types → default categories & units

`config/business_types.php` maps each business type (`grocery`, `pharmacy`,
`mobile`, `clothing`, `cosmetics`, `supershop`, `general`) to a seed list of
product categories, units, and which optional product fields are relevant
(`expiry`, `batch`, `imei`, `size`, `color`). When admin creates a shop and
picks a business type, `App\Support\ShopProvisioner` copies that type's
categories/units into the new shop's own `product_categories`/`units` rows.
From then on the shop owns those rows and can add their own on top (same
"+ new category" / "+ new unit" pattern as the original HTML prototype) —
admin can also add entirely new business types via `/admin/business-types`.

### Sales mode (scan vs manual), enforced server-side

Each shop has a `sales_mode`: `scan`, `manual`, or `both`, set by admin.
`App\Http\Middleware\CheckSalesMode` forbids the barcode-lookup route (web
and API) with a 403 for `manual` shops — this is **not** just a hidden
button; hitting the endpoint directly is blocked server-side too. The POS
page (`resources/js/Pages/App/Pos/Index.vue`) only renders the 📷 scan
button when `salesMode` is `scan`/`both`, using `html5-qrcode` for the
camera (same library the original HTML prototype used) — same UI/UX,
now backed by a server-enforced permission instead of just a hidden button.

### Feature permission system (admin → shop) — the dynamic capability layer

A `features` catalog table (`memo_whatsapp`, `memo_print`, `barcode_printing`,
`unit_conversion`, …) plus a `shop_features` pivot decide which optional
capabilities each shop has. Admin grants/revokes these from the shop
create/edit form (checkboxes, grouped by category) — **granting a feature
takes effect immediately, with no code or deploy**, because every check goes
through one method: `Shop::hasFeature(string $key)`. That method is used in
three places that all have to agree, which is what makes this a real
permission system rather than cosmetic:

1. **Route middleware** — `->middleware('feature:barcode_printing')` on the
   actual routes (`App\Http\Middleware\EnsureFeatureEnabled`), so hitting the
   URL directly without the grant is a 403, not a rendered page.
2. **Shared Inertia prop** — `HandleInertiaRequests` exposes the current
   shop's enabled keys as `features`, which the Vue pages read to decide
   whether to render a nav link/button at all.
3. **Admin's own catalog page** (`/admin/features`) — adding a brand new
   feature is inserting one row; it becomes grantable to any shop immediately,
   no other code changes needed. This is literally the "add a feature later
   and it just works" requirement.

Adding a **new** capability later means: add a row on `/admin/features`, add
one `feature:<key>` middleware call on the route(s) it should gate, and
(optionally) one `v-if="features.includes('<key>')"` in the relevant Vue
page — the permission plumbing itself needs no changes.

`tests/Feature/FeatureGatingTest.php` proves a shop without a grant gets a
real 403, and that granting it (the same way the admin UI does) immediately
unlocks the route.

### Cashier permission system (owner → staff) — a second, independent layer

Separately, each shop owner can add **one cashier account** (`users.role =
'staff'`) and decide exactly which app sections that cashier can reach, from
a fixed catalog in `config/staff_permissions.php` (POS, stock, due, accounts,
reports, expenses, …) — a checklist in the shop app itself (More → Cashier),
not the admin panel. This is a second, independent enforcement layer inside
the first: a shop needs a `feature` grant from admin *and* a cashier needs a
`perm` grant from their owner to reach the same route in some cases (e.g.
barcode label printing is gated by both `feature:barcode_printing` and
`perm:barcode_labels`).

- `User::hasPermission(string $key)` — the owner role always returns `true`
  (an owner is never restricted by this system); a staff account checks its
  own `permissions` JSON column.
- `->middleware('perm:accounts')` on routes enforces it server-side, same
  pattern as the feature layer.
- `->middleware('owner')` (`EnsureOwner`) protects cashier management itself
  — a cashier can never grant themselves or anyone else more access, even if
  given every other permission.
- Because `User` can't carry the full tenant-scoping trait (login has to look
  up a user by phone *before* a tenant is known), `StaffController` verifies
  `shop_id` ownership explicitly on every action instead of relying on
  route-model-binding to do it — see the comment on
  `StaffController::assertOwnedByCurrentShop()`.

`tests/Feature/StaffPermissionTest.php` proves: an owner can create exactly
one cashier with a chosen permission set; a second cashier is rejected; a
cashier without a grant gets 403 on that route and 200 on a granted one; a
cashier can't manage staff even with every other permission; and an owner
can't edit another shop's cashier.

### Selling broken-down packs (box → strip → piece)

For shops with the `unit_conversion` feature: a product's `stock` always
stays in its smallest sellable unit (piece/tablet), and `product_units`
defines additional pack sizes on top (e.g. "Box" = 100 pieces, priced as a
bundle) — no separate stock counter per pack size, so there's only ever one
number that can drift. `PosController::checkout` resolves the unit
**server-side** from the `product_unit_id` the client sends (never trusting
a client-supplied price/factor): it decrements `stock` by `qty × factor`
regardless of which unit was tapped, and snapshots the unit label + factor
onto `sale_items` so historical receipts stay accurate even if pack sizes
change later. Covers both requested cases — breaking a box of candy into
loose pieces, and selling 2 tablets out of a 10-tablet strip.

`tests/Feature/UnitConversionTest.php` proves: selling 1 box decrements
stock by the full factor; mixing a pack-unit line and a loose-unit line for
the same product in one bill computes correctly; and a `product_unit_id`
that belongs to a *different* product is rejected without touching stock.

### Memo delivery & printing (feature-driven, independently toggleable)

Two independent per-shop features control how a completed sale's memo can
leave the POS — a shop can have either, both, or neither:

- **`memo_whatsapp`** — the existing `wa.me` deep-link flow (unchanged).
- **`memo_print`** — an 80mm-receipt-styled printable view (`window.print()`
  with a `@media print` rule that hides everything except the receipt),
  showing the shop's uploaded logo (`shops.logo_path`, via
  `Shop::logo_url` → `storage/app/public/shop-logos/`), name, phone, and
  line items — usable with any POS thermal printer through the browser's
  print dialog.

Both buttons are gated the same way: `v-if="features.includes('memo_whatsapp')"`
/ `'memo_print'` in the receipt sheet — reusable in the POS checkout receipt
and on the sales-history detail page (`/app/sales/{sale}`), which shares the
same print/WhatsApp logic for reprinting an old memo.

### Barcode label printing (regular + discount price)

Shops with the `barcode_printing` feature get `/app/barcode-labels`: pick
products (with a copies count each), and it renders a print sheet of
`CODE128` barcodes (via `jsbarcode`) sized for label printers, showing the
regular price struck through and the `discount_price` (a new nullable
column on `products`) next to it when one is set — otherwise just the
regular price.

### Per-shop isolated data export — two formats, two purposes

Both live on the shop's admin detail page (`/admin/shops/{shop}`), admin-only
(`guard:admin` — a shop owner has no route to either, verified by
`ShopDataExportTest`):

1. **Excel** (`GET /admin/shops/{shop}/export`, `App\Exports\ShopDataExport`)
   — one sheet per table, for a human to open and read.
2. **SQL** (`GET /admin/shops/{shop}/export-sql`, `App\Support\ShopSqlDump`)
   — a real, standalone `.sql` file of `INSERT` statements (primary keys and
   foreign keys intact, tables ordered so parents always insert before
   children, wrapped in a transaction with FK checks briefly disabled) built
   directly with scoped `DB::table(...)->where('shop_id', $shop->id)` queries
   — not through Eloquent's tenant scope, so it can't silently return zero
   rows the way a scope-dependent read can (see the DemoShopSeeder bug below).
   Hand this file to a shop that wants to self-host or migrate: run
   `php artisan migrate` on a fresh install of this codebase, then
   `mysql <db> < their-export.sql`, and that one shop's entire history is
   back — no other shop's data was ever touched or exposed. Verified by hand
   against a real throwaway database (`zaylotix_import_test`) — full round
   trip, product↔category↔unit relationships intact.

### A seeder bug this export work surfaced (fixed)

Building the SQL dump involved inspecting real seeded data closely, which
caught a real bug: **every demo product's `category_id`/`unit_id` were
silently `NULL`.** `ShopProvisioner::provision()` clears the tenant context
(`Tenancy::clear()`) before returning — correct, since nothing is
authenticated as that shop yet — but `DemoShopSeeder` then ran tenant-scoped
reads (`ProductCategory::where(...)`, `Unit::where(...)`) *without*
re-binding the tenant first. Per the tenant-isolation design ("no tenant
resolved → see nothing, never everything"), those reads silently returned
empty collections instead of erroring, so `$cat[$key]->id ?? null` quietly
fell through to `null` on every single product, shop after shop. Fixed by
explicitly `Tenancy::set($shop->id)` right after `provision()` returns and
`Tenancy::clear()` at the end of each seeding method. This is exactly the
"safe failure mode" tradeoff called out at the top of this README — it never
leaked data, but it can hide a bug behind silence instead of a loud error,
which is why the SQL dump (built on raw scoped queries, not the Eloquent
tenant scope) was worth building even beyond the "self-host" use case: it's
also a good way to eyeball what a shop's real data looks like.

### Sales history, invoice search, and "my sales"

`/app/sales` lists sales with a search-by-`invoice_no` box and a "শুধু আমার
বিক্রি" (only my sales) toggle that filters to `sales.user_id = <logged-in
user>` — useful once a shop has a cashier, so both the owner and the cashier
can find "who sold what" without digging through the full ledger. Each row
opens `/app/sales/{sale}` for the full memo (reprintable/re-sendable via the
same feature-gated buttons as checkout).

---

## Data model

`business_types`, `shops` (+ `logo_path`), `admins`, `users` (shop
owner/staff, + `permissions` json), `features` + `shop_features` (admin →
shop capability grants), `product_categories`, `units`, `products` (+
`discount_price`), `product_units` (pack sizes), `customers`, `sales`,
`sale_items` (+ `unit_label`/`unit_factor` snapshot), `payments` (due
collections), `purchases`, `expense_categories`, `expenses`, `damages`,
`returns` (model `SalesReturn` — `return` is a reserved PHP keyword),
`stock_counts`, `subscription_payments`. Every tenant-owned table has an
indexed `shop_id`.

---

## Setup

Requirements: PHP 8.2+, Composer, Node 18+, and either SQLite (zero-config)
or MySQL/MariaDB (e.g. XAMPP) for a closer-to-production setup.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# SQLite needs no further DB setup — database/database.sqlite is created
# automatically by the migrate step below (DB_CONNECTION=sqlite in .env).
#
# For MySQL/MariaDB (e.g. XAMPP), first create an empty database (via
# phpMyAdmin or `mysql -u root -e "CREATE DATABASE zaylotix_pos"`), then in
# .env set: DB_CONNECTION=mysql, DB_DATABASE=<your db name>, DB_USERNAME=root,
# DB_PASSWORD= (XAMPP's default root user has no password).

php artisan migrate --seed
npm run build      # or `npm run dev` while developing
php artisan serve
```

Visit `http://127.0.0.1:8000`. With MySQL, all data is immediately visible
in phpMyAdmin under whichever database name you set in `DB_DATABASE`.

### Demo credentials

| Role | Login | Password |
|---|---|---|
| Super admin | `admin@zaylotix.com` (at `/admin/login`) | `password` |
| Shop owner — Khaled Enterprise (grocery, full demo data) | `01979894356` (at `/login`) | `1234` |
| Shop owner — Sasto Pharmacy | `01700000002` | `1234` |
| Shop owner — City Mobile Center | `01700000003` | `1234` |
| Shop owner — Fashion Point (clothing) | `01700000004` | `1234` |
| Shop owner — Glow Cosmetics | `01700000005` | `1234` |
| Shop owner — Agora Mart Demo (supershop) | `01700000006` | `1234` |
| Shop owner — General Store Demo | `01700000007` | `1234` |

The Khaled Enterprise shop is a full port of the HTML prototype's demo data
(same products, customers, sales, expenses, purchase, and damage record) and
has **all four features granted** (WhatsApp memo, POS print, barcode
printing, unit conversion) — including a "Center Fruit" box product sellable
whole or broken into loose pieces. The other six are lighter demo shops, one
per remaining business type, each showing that type's default
categories/units and optional fields (e.g. the pharmacy shop's product has
`expiry_date`/`batch_no` plus a strip→tablet pack unit, the mobile shop's
has `imei`). Feature grants per demo shop: pharmacy → WhatsApp + print + unit
conversion; mobile → WhatsApp + print + barcode printing; supershop → all
four; clothing/cosmetics/general → WhatsApp only. Use `/admin/shops/{id}/edit`
to grant more at any time.

### Running tests

```bash
php artisan test
```

40 tests covering tenant isolation, checkout transaction safety, guard
separation, subscription expiry, that every page in both the shop app and
admin panel actually renders (catches a typo'd `Inertia::render()` path
before it becomes a blank screen in the browser), the authenticated
same-tenant "happy path" for product update/delete and payment collection
(`ShopOperationsTest` — see "Verified working end-to-end" below for why that
group matters), the admin↔shop feature-grant system (`FeatureGatingTest`),
pack-unit checkout math (`UnitConversionTest`), per-shop data export
(`ShopDataExportTest`), and the owner↔cashier permission system
(`StaffPermissionTest`).

### Scheduling (backups & subscription expiry)

Two artisan commands are registered in `routes/console.php`:

```php
Schedule::command('zaylotix:expire-subscriptions')->dailyAt('00:05');
Schedule::command('zaylotix:backup')->dailyAt('02:00');
```

Run the scheduler from cron (once, standard Laravel pattern):

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan zaylotix:backup` copies the SQLite file (if `DB_CONNECTION=sqlite`)
or runs `mysqldump` (if `DB_CONNECTION=mysql`, e.g. XAMPP) to the disk
configured by `BACKUP_DISK` in `.env` (`config/backup.php`). Either way the
file lands at **`storage/app/private/backups/`** (both branches go through
the same `Storage::disk()` call, so the location is identical regardless of
which database you're running) — set `BACKUP_DISK=s3` and configure the
`AWS_*` vars for off-server backups in production. `mysqldump` must be on
`PATH` (XAMPP ships it at `xampp/mysql/bin/mysqldump.exe`).

---

## API (for the mobile app / PWA)

`routes/api.php` exposes a Sanctum token-authenticated API mirroring the
shop app's core flows (`POST /api/login`, `GET /api/products`,
`POST /api/pos/checkout`, `GET /api/customers`, …) so the same backend can
serve both the browser and a wrapped mobile app. Token requests go through
the same `CheckSalesMode` and an API-flavoured subscription check
(`EnsureApiShopActive`) as the web routes — no functional gap between the
two surfaces.

---

## PWA & building the Android APK

The shop app is already a mobile-first, installable PWA:
`public/manifest.json` + `public/sw.js` (registered in `resources/js/app.js`,
production builds only) + `public/icons/icon.svg` (the Zaylotix mark, used as
the favicon and PWA icon today). **Before shipping, also generate raster
icon PNGs** at `public/icons/icon-192.png`, `icon-512.png`, and a maskable
`icon-maskable-512.png` from the same mark — some install prompts (notably
older Android/iOS flows) still require PNG rather than SVG; the manifest
already references them, they just aren't checked into this repo yet.

To wrap it as an installable Android APK with [Capacitor](https://capacitorjs.com/),
which loads the deployed site in a WebView with native camera access for the
barcode scanner (`html5-qrcode`):

```bash
npm install @capacitor/core @capacitor/cli @capacitor/android
npx cap init "Zaylotix POS" "com.zaylotix.pos" --web-dir=public

# point Capacitor at your deployed HTTPS URL rather than bundling the build,
# so the app always talks to the live backend and never ships stale data:
# in capacitor.config.json set:
#   "server": { "url": "https://your-deployed-domain.example", "cleartext": false }

npx cap add android
npx cap sync android
npx cap open android   # opens Android Studio — build/sign the APK from there
```

Notes:
- Camera permission for barcode scanning is requested automatically by the
  WebView the first time `openScanner()` runs; no native plugin needed since
  `html5-qrcode` works directly against `getUserMedia` in a WebView.
- Because `server.url` points at your live deployment, all data is always
  server-authoritative — the APK never holds its own copy of the database
  (unlike the original single-file HTML demo, which only had `localStorage`).
- Sign the release APK with your own keystore in Android Studio
  (`Build → Generate Signed Bundle / APK`) before distributing.

---

## Verified working end-to-end

Every button in the shop app and admin panel was audited against its
backend route (all 45 `route()` calls in the Vue frontend cross-checked
against the actual Laravel route list — zero typos), and every write action
was smoke-tested against a real MySQL database with real HTTP requests
(login, checkout with stock decrement, barcode scan add-to-cart, product
edit, product delete, stock-in, due collection, mark-fully-paid, custom
date-range reports, admin shop create/edit/activate/deactivate, business
type toggle, subscription payment recording). Two real bugs were found and
fixed during that pass:

1. **Checkout was silently broken end-to-end** — `resources/views/app.blade.php`
   never emitted a `<meta name="csrf-token">` tag, so the POS page's `fetch()`
   call (needed because checkout returns JSON, not an Inertia response) sent
   an empty CSRF header and every checkout would have failed with 419 in a
   real browser. Fixed by adding the meta tag; verified with a real
   authenticated checkout request afterward.
2. **Product edit/delete and due-payment collection were fatal-erroring**
   — `app/Http/Controllers/Controller.php` didn't include Laravel's
   `AuthorizesRequests` trait (dropped from the default skeleton in Laravel
   11+), so every `$this->authorize(...)` call in `ProductController` and
   `PaymentController` threw "Call to undefined method authorize()". Cross-
   tenant tests didn't catch it because route-model-binding already 404s
   before the policy check runs on a *different* shop's product — only the
   same-tenant "happy path" hit the broken line. Fixed by adding the trait;
   `tests/Feature/ShopOperationsTest.php` now covers exactly this path so it
   can't regress silently again.

---

## What's intentionally out of scope

- Payment gateway integration for subscriptions — admin records payments
  manually, per the brief.
- More than one cashier per shop — `StaffController::store` caps it at one;
  the underlying model (`users.role = 'staff'` + a `permissions` json
  column) has no structural reason it couldn't support several, so lifting
  the cap later is a small, isolated change if a shop ever needs a second
  till.
- Real push notifications for low-stock/due reminders — WhatsApp deep-links
  (`wa.me`) are used instead, matching the original prototype's approach.
- Raster PNG icons for the PWA manifest — only the SVG mark is checked in
  (see "PWA & building the Android APK" above).
