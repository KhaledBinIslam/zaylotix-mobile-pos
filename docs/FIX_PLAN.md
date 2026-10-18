# Fix Plan — Zaylotix POS/ERP comprehensive audit

Ground rules in force for every item below (per Khaled's master prompt): never delete/rename a working feature; additive-only migrations with safe defaults/backfills; root-cause fixes, not symptom patches; server-side single-source-of-truth money math inside DB transactions with row locks; strict multi-tenant scoping on anything new; no raw 500s; idempotent money-moving endpoints; test before and after every phase; preserve existing code style. See `docs/ARCHITECTURE_NOTES.md` for full research detail behind each item.

Work proceeds phase 0 → 9, in order. Each phase stops for Khaled's explicit "server update done" confirmation before the next begins.

---

## Phase 0 — Universal shop-type support + provisioning reliability (most important)

### 0.1 — ShopProvisioner idempotency + consolidate the two creation paths
- **Root cause**: `ShopProvisioner::provision()` (`app/Support/ShopProvisioner.php:18-70`) has no duplicate-guard of its own; `Admin\ShopController::createBranch()` (`app/Http/Controllers/Admin/ShopController.php:176-224`) is a second, independent shop-creation path that bypasses it entirely.
- **Solution**: Add a `provisionOrReuse()`-style idempotent entry point to `ShopProvisioner` (check-by-phone-under-lock before insert, mirroring `DemoShopSeeder::provisionOrReuse()`), catch the duplicate-key race with a friendly validation-style error instead of an uncaught 500. Leave `createBranch()`'s distinct behavior (no new User, clone catalog from parent) intact — document the intentional divergence rather than forcing a merge that would change branch-creation semantics.
- **Risk**: Low — additive guard only, no change to the success path for a non-duplicate request.
- **Test**: Two near-simultaneous signups/admin-creates with the same phone → exactly one shop created, the second gets a friendly error, not a 500.

### 0.2 — Business-type/config drift safety net
- **Root cause**: `ShopProvisioner.php:31-32` resolves defaults from `config('business_types.<slug>')`; `BusinessType` rows are freely creatable from Admin with no matching config entry required (`BusinessTypeController.php:19-32`), silently producing a shop with zero categories/units/features.
- **Solution**: At provisioning time, if `config('business_types.'.$slug)` resolves empty for a non-null business type, fall back to the `general` profile's defaults (never leave a shop with literally nothing) and log a warning. Separately, add a validation check in `BusinessTypeController::store()` so creating a new business type without a matching config array surfaces a clear admin-facing warning rather than failing silently three steps later.
- **Risk**: Low — fallback only triggers in the already-broken case; never changes behavior for business types that do have a config entry.
- **Test**: Create a `BusinessType` with no config entry, provision a shop under it → shop ends up with the `general` profile's categories/units, not zero.

### 0.3 — `ShopProvisioner` should own the Tenancy re-set contract
- **Root cause**: `provision()` calls `Tenancy::clear()` (line 65) before returning; three separate call sites in `DemoShopSeeder.php` carry near-duplicate warning comments about having to manually re-`Tenancy::set()` afterward — a footgun for the next caller.
- **Solution**: Either (a) don't clear tenancy inside `provision()` and let the caller clear it when actually done, or (b) add a `provisionAndKeepContext()` variant used by callers that need to immediately do more tenant-scoped work. Keep the existing public signature's behavior unchanged for current callers to avoid a regression.
- **Risk**: Low-medium — must verify every existing caller (`SignupController`, `Admin\ShopController::store`, `DemoShopSeeder`) still behaves identically after the change.
- **Test**: Existing `DemoShopSeederTest`, `SelfServeSignupTest` stay green; add a case creating a product immediately after provisioning via the admin path to confirm tenant context is correct without a manual re-set.

### 0.4 — Variant/pharmacy/unit systems: already fully built, two real gaps to close
- **Root cause / gap 1**: `weight_unit` enum hard-locked to `kg`/`litre` (`StoreProductRequest.php:44`, `Stock/Index.vue:671-673`) — no loose-selling by meter/feet/yard for hardware/textile shops.
- **Solution**: Extend the enum (e.g. add `meter`) + matching i18n labels + UI segment option. Additive — existing `kg`/`litre` shops see zero change.
- **Root cause / gap 2**: `QuotationController.php:53` forces integer qty and excludes variant/pack products by design, blocking fractional-kg quotations.
- **Solution**: Allow decimal qty specifically for `sold_by_weight` products in quotations, mirroring the exact gating logic already proven correct in `PosController::performCheckout()` (`PosController.php:258-261`).
- **Risk**: Low — both are narrow, additive extensions of existing, already-correct patterns.
- **Test**: New quotation test for a weighed product with a fractional qty; new product test for `weight_unit = meter`.

### 0.5 — Cosmetics / grocery / department verticals
- **Finding**: Already fully supported via existing generic features (variants for shades, batch_tracking for expiry, multi-level categories already exist). No code gap found — confirmed via research, no action needed beyond 0.4's unit fix which benefits grocery/hardware too.

### 0.6 — `php artisan shop:smoke-test {type}` command
- **Solution**: New Artisan command that, for a given business-type slug (or `--all`), provisions a throwaway shop in a DB transaction that's rolled back at the end, exercises: category/unit presence, a product create, a POS checkout, a purchase, and a return — asserting no exception at any step. Add corresponding automated Feature tests per shop type and one mixed-shop (many features enabled) test, mirroring the existing `DemoShopSeeder::groceryFlagship()` pattern.
- **Risk**: Low — read/write only inside a rolled-back transaction, never touches real data.
- **Test**: The command itself IS the test entry point; wrap it in a Feature test asserting exit code 0 for every registered business type.

---

## Phase 1 — Urgent stability fixes

### 1.1 — Stock page 500 (confirmed root cause)
- **Root cause**: `ProductController::stockIn()` (`app/Http/Controllers/App/ProductController.php:307-309`) re-fetches the product under `lockForUpdate()` with no null-guard; if soft-deleted between route-binding and re-fetch, `$locked` is `null` and the strictly-typed `StockIn::apply(Product $lockedProduct, ...)` throws an uncaught `TypeError`.
- **Solution**: Add `if (! $locked) { abort(422, 'এই পণ্যটি মুছে ফেলা হয়েছে।'); }` immediately after the lock, mirroring the exact guard already present in `ProductVariantController::stockIn()`/`::destroy()`. Also guard `StockIn.php:22-27`'s division (`$lockedProduct->stock + $qty > 0`) defensively.
- **Risk**: Very low — pure defensive addition, matches an existing proven pattern.
- **Test**: Soft-delete a product then attempt stock-in on the stale reference → friendly 422, not a 500.

### 1.2 — Offline sale sync idempotency (confirmed core fragility)
- **Root cause**: `enqueueSale()` (`offlineQueue.js:5-13`) generates a client UUID but never sends it; `CheckoutRequest`/`PosController::performCheckout()` have zero duplicate-detection. A lost response after a committed transaction causes the queued entry to be resubmitted, double-selling and double-decrementing stock.
- **Solution**: Add `client_uuid` to the enqueued payload and to `CheckoutRequest::rules()` (nullable, uuid). Add a nullable, uniquely-indexed `client_uuid` column to `sales` (per-shop unique, additive migration). In `performCheckout()`, under the existing shop lock, check-or-create by `client_uuid` — if a sale with that uuid already exists for this shop, return it instead of creating a new one (same response shape, so the frontend's existing receipt-opening logic needs no change).
- **Risk**: Medium — touches the money-critical checkout path; must be covered by tests proving a replayed request with the same uuid never double-decrements stock or double-charges.
- **Test**: Submit the same checkout payload+uuid twice → exactly one `Sale`, one stock decrement, same `Sale` returned both times.

### 1.3 — Offline sale UX gaps (receipt-on-queue, notify-on-sync)
- **Solution**: Show a minimal "queued, sale #pending" indicator at queue time (not a full receipt, since server-assigned invoice number doesn't exist yet); fire a toast/notification with a link to the real sale once `trySync()` successfully syncs it.
- **Risk**: Low — purely additive UI, no data-path change.
- **Test**: Manual/Puppeteer check — queue offline, go online, confirm toast + link appears.

### 1.4 — Pages not auto-refreshing
- **Note**: Not yet root-caused in this research pass (no agent was dedicated to this specific claim beyond the SW/offline-sync audit, which found the service worker itself behaves correctly — network-first for `/app/*`, no POST caching). Needs a short follow-up check of Inertia's own page-prop refresh/polling pattern before coding — will confirm root cause at the start of Phase 1 implementation rather than guessing here.

---

## Phase 2 — Billing-popup correctness (server-side BillingCalculator + 11 sub-issues)

| # | Issue | Verdict | Fix |
|---|---|---|---|
| 1 | Partial cash+due | Already correct | No fix needed |
| 2 | Qty input width | Cosmetic | Widen `.qn-input` (`app.css:349`) for 3+ digit values |
| 3 | No per-item remove button | **Confirmed bug** | Add an explicit "✕" button next to each cart line (`Index.vue:1140-1144`, `1213-1217`) calling a direct `removeLine(line)` rather than requiring decrement-to-zero |
| 4 | Customer name overwritten on phone change | **Confirmed bug, serious** | Reset `customerName.value = ''` in the `watch(customerPhone, ...)` handler (`Index.vue:796-815`) whenever the phone actually changes to a different value; additionally harden `PosController.php:360-362` to only update an existing customer's name when the cashier explicitly edited it this session (e.g. a `name_touched` flag from the client), never as a blind mismatch-overwrite |
| 5 | No search-by-name | Missing feature | Add a name-based customer lookup endpoint + a small autocomplete dropdown in the customer field, additive alongside the existing phone lookup |
| 6 | No customer edit | Missing feature | Out of scope for Phase 2 (billing popup) — revisit under Phase 7's CRM/segmentation work |
| 7 | Coupon causes false "exceeds total" error | **Confirmed bug** | Build the server-side `BillingCalculator` (ground rule 6) as the one source of truth for subtotal/discount/coupon/loyalty/total; add a lightweight preview endpoint the client calls when a coupon code or redeem-points value changes, so `effectiveDiscount`/`total` on the client always match what checkout will actually charge before `buildPayments()` runs |
| 8 | Decimal qty | Already correct | No fix needed |
| 9 | BOGO doesn't auto-add | **Confirmed gap** | Either (a) auto-insert the free reward line into the cart display when the trigger condition is met (clearly marked "free," removable like any line, recomputed via the same `PromotionEngine` on every cart change), or (b) at minimum show a clear "you qualify for N free X" banner before checkout so the cashier manually adds it — (a) is the real fix, (b) is an interim UX improvement if (a) proves too invasive |
| 10 | Loyalty redemption | Already correct | Covered by the same `BillingCalculator` fix as #7 |

- **Risk**: The `BillingCalculator` consolidation is the highest-risk item this phase — it must reproduce `PosController.php:386-398`'s existing math exactly (manual discount + coupon + loyalty, capped at subtotal) so no existing sale's totals change. Build it as a pure function first, write characterization tests against current `performCheckout()` behavior before wiring the client preview endpoint to it.
- **Test**: Full `PosController`/checkout test suite stays green; new tests for the preview endpoint matching final checkout totals for coupon-only, loyalty-only, and combined cases; regression test for the BOGO free-line add/remove cycle; regression test for the customer-name-overwrite fix (edit phone to a different existing customer, confirm their name is never touched).

---

## Phase 3 — Customer due/ledger integrity

### 3.1 — Exchange math (confirmed real bug)
- **Root cause**: `ReturnController::createExchangeSale()` (lines 657-816) computes the correct `net_settlement = newTotal - credit` (line 718) but persists the gross `newTotal` as `Sale.total` (line 770); the credit is never recorded as a `SalePayment` or any persisted field. `Sales/Show.vue:27-30`'s generic `dueRemainder = total - tendered` then overstates due by exactly the credit amount on every later view/memo/WhatsApp share, and can seed a bogus installment plan.
- **Solution**: Record the applied trade-in credit as its own `SalePayment` row (new `method = 'trade_credit'`, matching the existing `SalePayment` shape so every report/balance calc that sums payments already handles it with zero special-casing — reusing the proven "sale is both order and invoice" convention). Leave `Sale.total` as the gross new-item price (so item-level reporting stays correct) but ensure `sum(sale_payments) + due` now correctly nets to `total` because the credit itself is a payment. Make `Sales/Show.vue`'s `dueRemainder` computation need no special-casing once this is in place, since it already just does `total - tendered`.
- **Risk**: Medium — must not change any `exchange`-type sale's existing revenue/profit reporting (confirmed already counted normally, per existing convention); only the payment/due side changes.
- **Test**: Reproduce the exact worked example from research (trade in ৳55, take ৳100, pay ৳45 cash) → `dueRemainder` on Sales/Show is ৳0, not ৳55; printed memo and WhatsApp text show ৳0 due; attempting to create an installment plan for this sale has nothing to schedule.

### 3.2 — Per-invoice due traceability (structural gap, design decision needed)
- **Finding**: `Customer.due` is a single running total; `Payment` has no `sale_id`; there's no way to see which invoices are still outstanding, only the total. This is the root structural cause behind 3.1 and a latent risk in void reversal (`SaleReversal.php`'s due-reversal math uses the sale's original due-at-checkout figure, not a live per-invoice balance).
- **Solution recommendation**: Add a nullable `sale_id` to `Payment` going forward (additive, backfill-impossible for historical lump payments — leave those `null`, document as "pre-ledger-upgrade" in the UI) so future payments can optionally be tagged to a specific invoice, enabling a real per-invoice outstanding view without breaking the existing lump-due model. This is a scope decision — flag to Khaled before building, since it changes how due collection could optionally work (FIFO-apply-to-oldest-invoice vs. keep as pure lump sum with optional tagging).
- **Risk**: Medium — purely additive column, but the UI/UX decision about whether to expose "pay against this specific invoice" needs Khaled's sign-off before building, per this session's standing practice of not guessing at functionality-altering decisions.
- **Test**: N/A until the design decision is confirmed.

### 3.3 / 3.4 / 3.5 — Returns-vs-due, void-vs-due
- **Finding**: Both already correct and transactional (`ReturnController.php:460-500`, `SalesController::destroy()` + `SaleReversal.php:25-92`). No fix needed — confirmed by code trace, not assumption.

---

## Phase 4 — Supplier / purchase management

### 4.1 — Free-text supplier doesn't create a Supplier record (confirmed bug)
- **Root cause**: `PurchaseController::store()` (lines 51-53) stores the free-text `supplier` string with no lookup-or-create against the `suppliers` table; `supplier_id` stays null, so the new supplier never appears in `Suppliers/Index.vue`, and credit purchases against it silently track no payable at all (`applyEffects()` only increments payable when `supplier_id` is present, lines 144-150).
- **Solution**: When `supplier` (free text) is given but `supplier_id` is not, do a `Supplier::firstOrCreate(['name' => $data['supplier']], [...])` scoped to the tenant inside the same transaction, and use the resulting id for payable tracking — exactly mirroring how the purchase form already invites "type name if not in list."
- **Risk**: Low — purely additive; existing purchases with an explicit `supplier_id` are unaffected.
- **Test**: Submit a purchase with only a free-text supplier name and `method=credit` → a new `Supplier` row exists, appears in the supplier list, and has the correct payable balance.

### 4.2 — Can't create a new product from the purchase form (missing feature)
- **Solution**: Add a "new product" option to the purchase form's product field (name + category + unit, reusing `ProductController::store()`'s existing inline-create pattern already proven for `new_category_name`/`new_unit_name`), creating the product and the purchase in one submit.
- **Risk**: Low-medium — must reuse existing product-creation validation exactly, not a parallel path.
- **Test**: Submit a purchase naming a brand-new product → product created, purchase attached to it, stock applied once.

### 4.3 — "No mark received button" — could not reproduce
- **Finding**: Button/status-check/route/permission all correct in code. **Action**: no code change; ask Khaled which exact screen he's on, or confirm the `purchases` feature/permission is granted to that shop, before spending further effort here.

### 4.4 — One-click receive, 4.5 partial supplier return — already correct
- **Finding**: Both fully transactional and correct. The one real caveat: variant products are blocked from supplier returns entirely (by design, redirects to the Stock page) — leave as-is unless Khaled asks to extend it.

### 4.6 — Per-supplier ledger page (missing feature, confirmed from scratch)
- **Root cause**: No `show()` method/route/Vue page exists; worse, `SupplierPaymentController::store()` never persists a structured payment row (only an `Activity` log line), so there's no queryable payment history table even once a ledger page is built.
- **Solution**: New `supplier_payments` table (mirroring the existing `SalePayment`/customer-payment shape: `supplier_id, amount, method, date`), written by `SupplierPaymentController::store()` alongside its existing payable/cash decrement. New `SupplierController::show()` + route + `Admin/Suppliers/Show.vue`-equivalent page showing total purchased, total paid, payable balance, a purchase-by-purchase breakdown, and a returns breakdown — same pattern as the customer due-history page (`PaymentController::history()`).
- **Risk**: Low — new table + new read-only aggregation page; no existing behavior touched.
- **Test**: New Feature tests for the supplier show page's totals matching sum(purchases) - sum(payments) - sum(returns-settled-as-payable).

### 4.7 — Free-text supplier return can't hit payable
- **Finding**: Same root cause as 4.1 — fixed automatically once 4.1's lookup-or-create is in place and `SupplierReturnController` is updated to use the resolved `supplier_id`.

---

## Phases 5-9 — Pending dedicated research before each begins

Per the master prompt's own phased structure, Phase 5 (service/repair refinement), Phase 6 (quotation strengthening beyond the integer-qty gap already found in 0.4), Phase 7 (payroll date-tracking, installment-creation bug, wholesale, customer segmentation/CRM), Phase 8 (bottom-nav customization), and Phase 9 (UI/UX redesign) will each get a short, dedicated research pass immediately before that phase's implementation starts, with findings appended to `ARCHITECTURE_NOTES.md` and this file at that time — consistent with the audit-before-fix discipline used for phases 0-4 above.

---

## Definition-of-done checklist (tracked across all phases, per master prompt)

- [ ] Per-business-type + mixed-shop smoke tests all passing (`shop:smoke-test`, Phase 0.6)
- [ ] `shops:repair-defaults --dry-run` run and reported (command does not yet exist — build in Phase 0 alongside 0.1/0.2)
- [ ] No new N+1 queries introduced by any phase
- [ ] Migrations verified both via `migrate:fresh --seed` and against existing data
- [ ] Money/stock reconciliation commands built and dry-run (`due:reconcile` — Phase 3)
- [ ] `docs/ARCHITECTURE_NOTES.md`, `docs/FIX_PLAN.md`, `docs/OFFLINE_TEST_CHECKLIST.md`, CHANGELOG all present
- [ ] Final comprehensive Bengali report for Khaled covering what changed, deploy steps, remaining risks, open questions
