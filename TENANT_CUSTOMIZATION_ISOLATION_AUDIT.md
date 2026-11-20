# Tenant-Specific Customization Isolation Audit

Scope: Section 19 (Tenant-Specific Customization Isolation), parts A-H, of the
full technical audit requested 2026-11-20. Investigated by direct code
inspection, reflection-based static analysis, a live `php artisan tinker`
query against production data, and a targeted run of the existing automated
regression suite. No production data was modified; no application file was
changed. Every claim below is backed by a file:line citation or a command
whose output is quoted.

Labels used: **VERIFIED PASS** (checked, confirmed safe) · **VERIFIED FAIL**
(checked, confirmed broken) · **NOT TESTED** (out of scope for this pass) ·
**BLOCKED BY MISSING ACCESS** (would need something this environment doesn't
have) · **POTENTIAL RISK** (checked, not currently exploitable, but fragile).

---

## 1. Existing customization architecture

Zaylotix is a single-codebase, shared-database multi-tenant app. Every shop
("tenant") is a row in `shops`, and tenancy is resolved once per request into
`Tenancy::id()` / `Tenancy::shop()` (`app/Support/Tenancy.php`). Three
independent customization layers exist, deliberately separate:

1. **Tenant data scoping** — which rows a shop can see at all. Enforced by
   Eloquent global scopes, not by controller-level filtering (see §5).
2. **Feature grants** — which *capabilities* a shop has turned on
   (`shops.hasFeature($key)`, backed by a `features` relation/pivot, not a
   hardcoded shop-name check). Confirmed single-source-of-truth: the same
   `hasFeature()` call gates both server-side middleware/controllers and the
   feature list serialized to the frontend
   (`app/Models/Shop.php:355-364`, its own docblock: *"The single source of
   truth for 'can this shop use capability X' — used both server-side... and
   to build the `features` array shared to the frontend"*).
3. **Business-group scoping** — for the handful of things that must span a
   root shop + its branches (customers, business dashboard, stock transfers,
   cross-branch returns), gated through one function,
   `Tenancy::businessShopIds()` (`app/Support/Tenancy.php:98-113`), never
   duplicated ad hoc. 11 call sites, enumerated in §4.

No shop-name or shop-ID string literal (e.g. `if ($shop->name === 'Global
Touch')`) was found anywhere in `app/` gating business logic. Per-shop
provisioning scripts (`app/Console/Commands/Setup*.php` — `SetupGlobalTouch`,
`SetupBabyPark`, `SetupSeamanShop`, `SetupStandardShop`, `SetupDemoClothingShop`,
`SetupDemoMobileShop`) exist, but these are one-time CLI provisioning tools
that *grant* feature keys to a shop at creation time — they are not runtime
conditionals, and were already out of scope as dev/admin tooling per the
parent audit's own instruction. **VERIFIED PASS.**

## 2. List of tenant-specific features

From `app/Console/Commands/Setup*.php::FEATURE_KEYS` and live grep of
`hasFeature(` call sites, the feature keys actually gating behavior today:
`pos`, `export`, `online_business`, `shop_backup`, `cashier_management`,
`low_stock_alerts`, `installment_sales`, `shared_catalog`,
`shared_stock_pool`, `shared_promotions`, `transfer_confirmation`,
`weighted_average_costing`, `source_lines`, `quotations`, `purchase_orders`,
`hr_payroll`, `service_billing`, `serial_tracking`, `barcode_printing`,
plus the two structural shop-row flags added this session, `is_warehouse`
and `is_head_office` (`shops` table columns, not feature-table rows — see §6
for why that distinction matters).

Two flags are explicitly root-shop-only, enforced in code, not just
convention: `shared_catalog` and `shared_stock_pool`
(`app/Console/Commands/SetupBabyPark.php:41` `MAIN_ONLY_FEATURES`, and
independently re-checked at every call site, e.g.
`app/Models/BusinessScope.php`-adjacent checks `! $shop->parent_shop_id &&
$shop->hasFeature(...)` in `ProductController.php:451`,
`PriceUpdateController.php:148`, `ProductImportController.php:287`).
**VERIFIED PASS** — granting a main-shop-only feature to a branch instead
is a no-op, confirmed by `SharedStockPoolTest`'s own test case *"granting the
feature to a branch instead of the main shop does nothing"* (passed, see §8).

## 3. Global vs. tenant-specific configuration mapping

| Config class | Mechanism | Example |
|---|---|---|
| Global (same for all shops) | PHP constants / `.env` | `QUEUE_CONNECTION`, VAT rounding rules, `PosController::FULL_CATALOG_LIMIT` |
| Per-shop toggle | `features` grant (admin-managed) | `online_business`, `shared_catalog` |
| Per-shop structural flag | `shops` table column | `is_warehouse`, `is_head_office`, `parent_shop_id` |
| Per-shop display customization | `shops` table columns / JSON | `memo_settings`, `receipt_footer`, label header text — confirmed precedence-tested: *"label header precedence business over default"*, *"a never customized shop resolves to exact system defaults"* (`ShopCustomizationTest`, passed, §8) |
| Per-shop data | row-level `shop_id` column + global scope | products, sales, customers, etc. |

No case was found where a *global* default and a *per-shop override* are
stored in the same column/row such that one shop's customization could leak
into the global default (which is the classic failure mode this section is
checking for). **VERIFIED PASS**, evidenced by `ShopCustomizationTest`'s
*"customizing one shop leaks nothing into another"* (passed, §8).

## 4. Potential cross-tenant contamination risks

All 11 call sites of the cross-branch gate `Tenancy::businessShopIds()` were
read and confirmed to receive only server-resolved input
(`Tenancy::id()`/`Tenancy::shop()`), never a request parameter:
`ArticleController.php:280,632`, `BarcodeLabelController.php:139`,
`CompanyDashboardController.php:34`, `ExportController.php:135`,
`ReturnController.php:210,382`, `StockMovementController.php:54`,
`BusinessScope.php:35`, `ArticleSerial.php:183`, `Reports.php:398`.
**VERIFIED PASS** — a request cannot widen its own business-group scope by
passing a different `shop_id` in the payload, because the function never
looks at the payload.

One genuine contamination-shaped risk was found and is written up in full in
§6 and §9: the new `is_warehouse` / `is_head_office` restriction is a
hand-written `abort_if` inside one HTTP controller method only, not a
centralized rule, which is exactly the "scattered hardcoded condition"
anti-pattern this section exists to catch — just inverted (the condition is
*missing* from sibling call sites, rather than a wrong condition leaking
somewhere).

## 5. Database isolation findings

**Mechanism.** `BelongsToTenant` (`app/Models/Concerns/BelongsToTenant.php`)
adds a global scope (`TenantScope`, `app/Models/Scopes/TenantScope.php`) that
runs on every query against the model, including ones built from implicit
route-model binding. Its fallback is fail-*closed*: if no tenant is resolved,
it filters `WHERE shop_id = 0` (an impossible id) rather than returning
unscoped data — `app/Models/Scopes/TenantScope.php`. Confirmed by
`TenantIsolationTest`'s own case *"queries without a resolved tenant return
nothing"* (passed, §8).

**Reflection-based enumeration.** A disposable script enumerated every
Eloquent model with a `shop_id` column and checked its trait usage
end-to-end rather than relying on manual grep. It flagged 5 models as "not
using BelongsToTenant"; each was individually resolved:

| Model | Finding | Verdict |
|---|---|---|
| `Customer` | Uses `BusinessScope` (business-group, not single-shop) **deliberately** — a customer's due/loyalty/visit history must follow them across branches, not the till. Own docblock explains this. `app/Models/Customer.php:1-40`. | VERIFIED PASS |
| `User` | Uses `StampsTenantOnCreate`, not `BelongsToTenant`, **deliberately** — login must look up a user by phone/email *before* any tenant is known, so no global read scope can exist on this model. Its own comment: *"Any shop-scoped listing of users must filter explicitly."* Checked every such listing: `StaffController.php` (3 explicit `where('shop_id', ...)` sites), `Reports.php:255-270,688-700` (user IDs always sourced from an already shop-scoped `Sale`/`SalesReturn` query first), `SaleNotifier.php:52` (ID from one specific, already-resolved sale). | VERIFIED PASS |
| `SubscriptionPayment` | Platform-billing record, created only from `GatewayWebhookController.php:185-220` using `$shop = Shop::whereKey($locked->shop_id)->lockForUpdate()->first()` — `$locked->shop_id` comes from the webhook's own DB-locked row, never from request input. Never exposed to a shop owner. | VERIFIED PASS |
| `AmbassadorCommission`, `AmbassadorShopAssignment` | Zero controller references anywhere in `app/` (grepped) — platform/admin-only, reached only via model relations + `AmbassadorCommissionService`, and the one write site (`SignupController.php:96-108`) only ever *creates* a record for the shop a signup just created for itself (never a cross-tenant read). | VERIFIED PASS |

Spot-checked models with non-obvious table names also confirmed correctly
scoped: `Promotion` (table `promotions`), `StockIntake` (table
`stock_intakes`), `PurchaseOrder` (table `purchase_orders`,
`app/Models/PurchaseOrder.php:10` `use BelongsToTenant;`).

**Raw-SQL bypass check.** `app/Support/ShopSqlDump.php:118-129` uses raw
`DB::table(...)` (bypasses Eloquent/global scopes entirely, by necessity —
it's a full-shop backup exporter) — confirmed every table query there is
explicitly `.where('shop_id', $shop->id)`. **VERIFIED PASS.**

**Overall: VERIFIED PASS.** No genuine cross-tenant database leak was found
in any shop_id-bearing model.

## 6. Cache and queue isolation findings

**Cache.** Only one `Cache::` call site exists in the entire `app/` tree:
`app/Http/Controllers/App/ArticleController.php:185,418` —
`"article:submitted:{$shop->id}:{$data['client_uuid']}"`, tenant-keyed.
`CACHE_STORE=database` (confirmed via `.env`); no Redis, no shared
in-memory store across requests. The only other cache-shaped thing,
`Tenancy::$shopCache` (`app/Support/Tenancy.php:25-53,137-140`), is a private
static PHP array that memoizes `Shop::find()` within one request only — it
does not survive between PHP-FPM requests and holds full `Shop` rows, not
query results, so it cannot leak one shop's data into another's response.
**VERIFIED PASS.**

**Queue.** `QUEUE_CONNECTION=database` (confirmed via `.env`). Exactly one
real `ShouldQueue` job class exists, `app/Jobs/GenerateShopBackupJob.php` —
its constructor takes a `ShopBackup $shopBackup` model (not raw IDs it has
to re-derive), and `handle()` does an explicit `Shop::findOrFail($backup->
shop_id)`. Exactly one real queued `Notification`,
`app/Notifications/SaleMade.php` — constructor takes scalars only, no
Eloquent model serialized into the queue payload (own comment explains why).
`app/Exports/ShopDataExport.php:1-40`'s `sheets()` explicitly calls
`Tenancy::set($this->shop->id)` as its first line, re-establishing tenant
context on the queue worker (queue workers don't inherit the web request's
resolved tenant automatically — this line exists because of that).
**VERIFIED PASS.**

## 7. API and permission isolation findings

Direct URL/API bypass (not just frontend button-hiding) was checked against
the sensitive routes most likely to be attempted against a sibling shop's
data:

- **`StockTransferController::accept()` / `reject()`**
  (`app/Http/Controllers/App/StockTransferController.php:344,377`) — takes a
  plain `int $transfer`, deliberately *not* implicit route-model binding
  (the model's own `BelongsToTenant` scope would always 404 the
  *destination* branch's legitimate call, per the method's own docblock).
  Looked up via `withoutGlobalScopes()->whereKey($transfer)`, then
  authorized explicitly: `abort_unless($locked->to_shop_id === $user->
  shop_id, 403, ...)` — `$user->shop_id` comes from the authenticated
  session, never from the request. A shop iterating `{transfer}` IDs
  belonging to another business gets a 403. **VERIFIED PASS.**
- **`StockTransferController::store()`** — cross-business transfer blocked
  by `abort_unless($sourceRoot === $destRoot, 403, ...)` (line 130), and the
  source `product_id` is validated server-side with `Rule::exists
  ('products','id')->where('shop_id', Tenancy::id())` (line 110) — a
  request cannot name another shop's product as the source. **VERIFIED
  PASS.**
- **`PurchaseOrderController::receive()`** — uses implicit route-model
  binding (`PurchaseOrder $po`); `PurchaseOrder` uses `BelongsToTenant`, so
  a cross-tenant PO id 404s before the method body even runs. The internal
  re-fetch (`PurchaseOrder::whereKey($po->id)->lockForUpdate()->first()`,
  line 142) stays scoped too (no `withoutGlobalScopes()` used here).
  **VERIFIED PASS.**
- **`ExportController::download(string $kind)`** — `$kind` is resolved
  through a closed `match` expression against a fixed list of report
  names (lines 43-59); there is no dynamic file path or class-string
  built from `$kind`, so no path-traversal/injection surface. Route is
  gated by both `perm:export` *and* `feature:export`
  (`routes/web.php:630-632`). All report-row builder methods rely on the
  ambient `BelongsToTenant` scope (confirmed consistent with the
  established, already-shipped pattern used by `salesRows()`/`stockRows()`
  etc.). **VERIFIED PASS.**
- **`CompanyDashboardController::index()`** (Business Dashboard) — no
  owner-only restriction (by design: any authenticated staff member sees
  their own business's dashboard), but `$shopIds` is derived from
  `Tenancy::businessShopIds($shop->id)` where `$shop = Tenancy::shop()`
  (server-resolved) — never request input. **VERIFIED PASS.**
- **Gateway checkout / webhook surface** — see §9, Finding 1. This is the
  one place a backend authorization gap was actually confirmed.

**Owner-only routes spot-checked for the "frontend-hides-the-button-only"
failure mode:** `stock-transfers/*` (`abort_unless($user?->role ===
'owner', 403)` at the top of every method), `shop-backups/*`
(`routes/web.php:695`, `['owner', 'feature:shop_backup']` middleware, not
just a hidden menu item). **VERIFIED PASS.**

## 8. Cross-tenant regression test results

Ran the existing automated suite's most directly relevant files (not the
full 1700+-test suite, to keep this pass's runtime proportionate — these 9
files are the ones whose names and content specifically target tenant/
branch/customization isolation):

```
php artisan test tests/Feature/TenantIsolationTest.php tests/Feature/BranchCrossReturnTest.php \
  tests/Feature/BranchCustomerSharingTest.php tests/Feature/SharedStockPoolTest.php \
  tests/Feature/SharedCatalogTest.php tests/Feature/ShopCustomizationTest.php \
  tests/Feature/BarcodeLabelDataExposureTest.php tests/Feature/RatingSecurityTest.php \
  tests/Feature/PaymentGatewayCredentialTest.php

Tests:    72 passed (381 assertions)
Duration: 142.27s
```

All 72 tests across all 9 files passed, 0 failures. Notable individual cases
directly on point: *"shop user cannot see another shops customers or dues"*,
*"an unrelated shop cannot find or return against another businesss sale"*,
*"an unrelated shops user cannot reach another businesss customer at all"*,
*"customizing one shop leaks nothing into another"*, *"credentials are
tenant scoped"*, *"cannot generate a barcode for another shops product"*.
**VERIFIED PASS.**

The full suite (1765+343 tests per prior project notes) was **NOT** re-run
in this pass — out of scope for this section, already covered by the
broader audit's own test-suite phase.

## 9. Confirmed bugs and their severity (P0–P3)

### Finding 1 — `is_warehouse` / `is_head_office` "cannot sell" rule is not enforced at the shared checkout choke point — **P2**

**What.** `PosController::checkout()` (`app/Http/Controllers/App/
PosController.php:363`, guard at lines 372-373) blocks a warehouse or
head-office shop from completing a sale:
```php
abort_if($currentShop?->is_warehouse, 422, '... শুধু স্টক ট্রান্সফার করা যায়।');
abort_if($currentShop?->is_head_office, 422, '... শাখা থেকে বিক্রি করুন।');
```
But the actual sale-creation logic lives in `PosController::
performCheckout()` (static, line 592) — a *separate* method `checkout()`
merely calls into. `performCheckout()` itself has no such guard, confirmed
by grepping `is_warehouse|is_head_office` across the entire `app/Http`
tree: the only two matches are the two lines quoted above. Confirmed by
grepping every one of `performCheckout()`'s 7 real call sites: 3 bypass the
guard entirely —

- `GatewayWebhookController.php:154` — calls `PosController::
  performCheckout($locked->checkout_payload, $locked->shop_id, $locked->
  user_id, forcedPayments: [...])` inside a DB transaction, on a real
  payment-gateway webhook callback. This **does** persist a real `Sale`.
- `GatewayCheckoutController::initiate()`
  (`app/Http/Controllers/App/GatewayCheckoutController.php:24-63`) — the
  route that *starts* a gateway checkout — also has no `is_warehouse`/
  `is_head_office` check, and sits behind only `perm:pos` middleware
  (`routes/web.php:205`), the *same* permission group as normal checkout,
  with no extra feature gate. So nothing upstream of the webhook blocks
  this either.
- `BillingPreview.php` / `GatewayPricePreview.php` — confirmed **safe**:
  both wrap `performCheckout()` in `DB::transaction()` and deliberately
  throw `GatewayPreviewRollback` to discard everything except the computed
  total (`app/Support/BillingPreview.php`, `app/Support/Gateways/
  GatewayPricePreview.php`) — no Sale or stock change ever survives. These
  two are **not** part of the bug.

**Why it's not P0/P1 today.** Live production query:
```
shop 147 (Global Touch, is_head_office) — active_gateway_credentials = 0
shop 149 (Global Touch - সেন্ট্রাল ওয়্যারহাউজ, is_warehouse) — active_gateway_credentials = 0
```
These are the *only two shops in the database* with either flag set
(confirmed earlier this engagement), and neither currently has an active
`PaymentGatewayCredential` (bKash/Nagad/SSLCommerz) configured. With no
credential, `GatewayCheckoutController::initiate()` itself aborts at line
35-37 before a payment can even start. **So this is not exploitable against
real data right now.**

**Why it's still a real, confirmed bug (not a non-issue).** The gap is
structural, not coincidental: nothing in the gateway-credential settings UI
or `GatewayCheckoutController` is aware of `is_warehouse`/`is_head_office`.
The moment anyone — Global Touch itself, or any future shop using these
flags — connects a bKash/Nagad/SSLCommerz credential to a warehouse or
head-office shop, a customer-initiated online payment would complete a real
`Sale` against a location the business rule says can never sell directly,
with no staff member or POS screen in the loop to catch it (it is a fully
automated webhook path). This is exactly the "condition duplicated instead
of centralized" anti-pattern this audit section targets — the restriction
lives on one of several callers into a shared method, not on the shared
method itself.

**Recommended fix** (not applied — read-only audit per scope): move the two
`abort_if` lines into `performCheckout()` itself, immediately after its
existing `$shop = Shop::whereKey($shopId)->lockForUpdate()->first();` at
line 595 — the method already fetches `$shop` there, so this needs no extra
query. That makes every current and future caller (HTTP checkout, webhook,
both previews, and any later integration) enforce the rule at the one real
choke point, instead of relying on each new caller to remember to duplicate
it. `checkout()`'s own copy at lines 372-373 can then be left as an early,
cheaper rejection (fails before opening a transaction) or removed —
either is safe once `performCheckout()` itself enforces it.

No other P0/P1/P2/P3 findings were confirmed in this pass. P0 count: 0.
P1 count: 0. P2 count: 1 (above). P3 count: 0.

## 10. Recommended architectural improvements

1. **Apply Finding 1's fix** — centralize the `is_warehouse`/
   `is_head_office` check inside `performCheckout()` (see §9).
2. **Consider a lightweight static assertion/test** that every
   `ShouldQueue` job and queued `Notification` added in the future either
   takes scalars only or explicitly re-resolves tenancy in `handle()` —
   today this holds for both existing classes, but it holds by convention,
   not by a check that would catch a future regression.
3. **No other structural change is recommended.** The feature-flag
   mechanism (`hasFeature()`), the two global-scope classes (`TenantScope`,
   `BusinessScope`), and the single `businessShopIds()` choke point are
   already exactly the "centralized, isolated mechanism" shape this section
   asks for — the one real gap found is a missing call, not a wrong
   pattern.

---

**Overall verdict:** No cross-tenant data leak exists in the database,
cache, or queue layers today (all VERIFIED PASS). One real, confirmed logic
bug (P2) was found in this session's own newest feature
(`is_warehouse`/`is_head_office`) — currently dormant/non-exploitable
against live data, but architecturally fragile and will misfire the first
time a gateway credential is connected to either of the two flagged shops.
