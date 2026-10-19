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

## Phases 5-8 — completed

Phase 5 (service/repair), Phase 6 (quotation strengthening), Phase 7 (payroll/installments/wholesale/CRM), and Phase 8 (bottom-nav customization) are all implemented, tested, and deployed. See commits `f0cf876`, `67f6df0`, `cf70efe`, `c8f7042`.

## Phase 9 — Premium UI/UX redesign via design tokens (logic unchanged)

Research pass (full findings in `ARCHITECTURE_NOTES.md` section 8): App/* is mostly token-driven with fixable drift; Admin/* is a wholly separate, untokenized Tailwind-default system; no dark mode exists; no font-size scale exists; 8 Breeze scaffold components are dead code. Ground rule for this entire phase: CSS custom properties + light class/template changes only — zero route/controller/model/business-logic changes.

### 9.1 — Bridge Admin/* onto the existing token system
- **Solution**: Add a `theme.extend.colors` block to `tailwind.config.js` mapping brand tokens to the CSS custom properties (e.g. `brand-purple: 'var(--purple)'`, `brand-green: 'var(--green)'`, `brand-rose: 'var(--rose)'`, `brand-mut: 'var(--mut)'`, `brand-line: 'var(--line)'`), then swap Admin/*'s raw Tailwind defaults and literal `#7C3AED`/`[background:#7C3AED]` arbitrary-values for the new `brand-*` utilities across `AdminLayout.vue` and `Admin/**/*.vue`.
- **Risk**: Low — pure color-value substitution, same rendered pixels (same hex), just sourced from one token instead of N literals.
- **Test**: `npm run build` clean; visual spot-check of Admin dashboard/login/shops pages unchanged in appearance.

### 9.2 — Fix concrete token drift in App/*
- Retrofit the ~15 files with literal inline `border-radius` values onto `--radius-sm/md/lg`.
- Fix `app.css:185`'s `.stat.sky` background (`#E3EFFB` → `var(--skySoft)`, removing the one-digit-off near-duplicate of the real token).
- Fix `Help/Index.vue:28`'s literal `#25D366` + custom glow shadow → `var(--wa)`, no glow (matching the app's existing no-colored-glow convention).
- Fix `BarcodeLabels/Index.vue:164`'s literal `#fff`/`#ddd` → `var(--panel)`/`var(--line)`.
- **Risk**: Very low — each is a value substitution with an identical or near-identical rendered color.
- **Test**: `npm run build` clean; visual spot-check of Stock, Pos, Help, BarcodeLabels pages.

### 9.3 — Add a font-size token scale (additive, zero visual change on day one)
- **Solution**: Add `--text-xs/sm/base/lg/xl` tokens to `:root` derived from the already-dominant real-world values (11/12.5/14.5/16/19px). Do not force-retrofit all 39+ existing literal usages (too invasive for a logic-unchanged phase) — the scale exists for future pages/edits to converge on.
- **Risk**: None — purely additive tokens, nothing references them yet.

### 9.4 — Name the elevation scale
- **Solution**: Add `--sh-sm`/`--sh-lg` alongside the existing `--sh`, formalizing the 2-3 shadow recipes already hand-rolled ad hoc (login/onboarding card, notif dropdown, wide sheet) as deliberate steps instead of accidental near-duplicates. Point those specific selectors at the new tokens.
- **Risk**: Very low — same or near-identical shadow values, now named.

### 9.5 — Scope decisions explicitly flagged to Khaled, NOT built (resolved in Phase 10 below)
- Dark mode (net-new feature, no existing infra).
- Whether Admin's purple should visually unify with App's green/gold brand, or stay deliberately distinct as an internal-console look (9.1 above assumes "bridge the token, keep the color" — a literal-value-preserving refactor, not a rebrand; the rebrand question itself is still Khaled's call).
- The PWA install theme color (`resources/views/app.blade.php`, `public/manifest.json`) currently matches Admin's purple, not the in-app functional palette — may be intentional, may be an oversight.
- Bumping `--dim`'s contrast (currently ~2.9:1) — a one-line token edit, but a visible app-wide color shift on every "quiet" text element.
- A loading-skeleton state — none exists anywhere; doing it right needs a per-page template conditional, beyond a pure token/class change.
- Deleting the 8 confirmed-unused Breeze components — zero risk, but a file-deletion action flagged for a nod rather than done silently.

## Phase 10 — Khaled's premium-feel follow-up to Phase 9 (completed, see commit `39cd12c`)

Dark mode, Admin's own premium color identity (kept separate from the shop brand), the PWA/TWA install theme color fix, a `--dim` contrast bump, a real boot splash + branded progress bar + spinner component, and deletion of 8 confirmed-unused Breeze components. All six of Khaled's explicit answers to Phase 9's flagged scope questions.

## Phase 11 — Offline-sync "product not found" permanent fix + Vite preload warning

Real reported production bug, with a full root-cause spec from Khaled. Research (dedicated background agent, read-only) confirmed the trigger: the offline sale queue is a single, un-namespaced, browser-global IndexedDB store (`resources/js/support/offlineDb.js`) with no shop/session pinning — a sale queued while one shop's session is active (classic trigger: an admin reusing one browser across impersonation sessions for different shops) can sit there past that session ending, then get replayed under a DIFFERENT shop's session. `Product::whereIn('id', ...)` is tenant-scoped (`TenantScope`), so every product_id in the stale entry genuinely doesn't exist under the new tenant — giving the misleading generic "product not found" for every line, and since `retry` just resubmits the identical payload under the identical (still-mismatched) session, it fails identically forever.

### 11.1 — Payload snapshot
- Cart lines now carry a diagnostic-only snapshot (`product_name`, `product_barcode`, `unit_label`, `variant_label`) built from the already-loaded product catalog at payload-build time (`Pos/Index.vue`'s `lineSnapshot()`), plus a top-level `shop_id`/`shop_name`. Never trusted for pricing/stock — only used to name a problem item and as a same-tenant barcode fallback.

### 11.2 — Shop-pin check
- `PosController::checkout()` compares the payload's `shop_id` against `Tenancy::id()` BEFORE the product lookup even runs; a mismatch returns 409 with the queued-for shop's name, instead of the old misleading per-line "not found". An older cached client / already-queued entry with no `shop_id` skips the check — no worse than before. Client-side, `useOfflineSync.js`'s `trySync()` does the same check pre-flight (before even attempting the request) and marks the entry with a new `'shop_mismatch'` status, distinct from a plain product problem.

### 11.3 — Server-side product resolution
- `performCheckout()` now: (a) resolves `withTrashed()` ONLY when the request is flagged `offline_replay: true` (set only by the sync replay path, never a live checkout) — a genuinely-queued sale rung up before its product was deleted can still sync; a fresh live attempt to sell a discontinued product still can't; (b) falls back to a same-tenant barcode match for a product_id that can't be found by id at all, rewriting the item's `product_id` to the resolved product's real id so every later stock/lookup in the method stays consistent; (c) collects ALL still-missing items upfront (not just the first) into a structured `{message, problem_items: [{product_id, product_name}]}` 422 response — every other existing validation (stock, unit/variant mismatch, qty) is unchanged and still fails fast on the first offender.
- Removed `CheckoutRequest`'s `exists:products,id` rule on `items.*.product_id` — it was table-wide, not tenant-scoped, so it never actually protected against a cross-shop id; it only blocked the new recovery flow from ever reaching the controller's much better per-item handling.

### 11.4 — Failure classification + resolution UI
- `trySync()` now classifies a failure as `'transient'` (network/5xx — the plain retry button still applies) or `'permanent'` (a real business rejection — resubmitting unchanged will fail forever). `AppLayout.vue`'s failed-queue sheet now shows, per problem item, "match to another product" (a small inline search against the new `GET /app/products/search` endpoint, tenant-scoped) or "drop item" (removes just that line, keeps the rest of the bill) — never silently discarding a multi-item sale over one bad line. A whole stuck entry (shop-mismatch or otherwise) can be cancelled with an optional reason, logged via `Activity::log()` (`POST /app/pos/discard-offline`) — there's no `Sale` row to void since it never reached the server, so this is purely a best-effort audit trail.

### 11.5 — Hard-delete check (already satisfied)
- Confirmed `ProductController::destroy()` only ever calls `$product->delete()` (soft delete via `SoftDeletes`) — grepped the whole `app/` tree for `forceDelete` and found no caller touching `Product` at all. No code change needed for this item.

### 11.6 — Vite CSS preload warning
- `app.blade.php`'s `@vite([...])` was passing BOTH `app.js` and the current page's own `.vue` component as entries — the Laravel-Breeze-starter default. Since a page component's own manifest entry lists `app.js` as an import, Laravel's Vite helper walked that graph and emitted a redundant `<link rel=preload as=style>` for `app.js`'s CSS on top of the normal `<link rel=stylesheet>` already emitted for it as its own entry — exactly Chrome's "was preloaded... but not used" warning. Dropped the page-component entry; Inertia's own `resolve()` (`import.meta.glob('./Pages/**/*.vue')` in `app.js`) already dynamically imports every page component at runtime regardless — the same mechanism every SPA navigation after the first page load already relies on.

### 11.7 — Recovery of the specific stuck bill Khaled reported (10/18/2026 9:34 AM)
- This fix can't reach into that specific device's browser remotely — the stuck entry lives in that browser's own IndexedDB, not on the server. Once this fix is deployed, reopening the app on that SAME device should surface it in the failed-queue sheet with the new, specific message (shop-mismatch or a named problem item) instead of the old generic one, and the resolution options above apply to it directly — no manual server-side intervention needed, but it does need that specific device/browser to be opened again.

### 11.8 — Follow-up fixes shipped the same day (real money bug + a UI gap)
- **Split-payment UI bug (commit `eaf73de`)**: `Pos/Index.vue`/`Clothing/Pos.vue` rendered a second payment-amount entry UI (three boxes with their own live "বাকি থাকবে" due calculation) UNCONDITIONALLY whenever not complimentary — not gated on "split mode" actually being on. `buildPayments()` only reads those boxes when split mode is true; otherwise it always sends the full total as one payment. A cashier typing a partial amount into the visible-but-disconnected box saw a correct due warning, then had the full total silently charged anyway on checkout. Fixed by gating that block on `splitMode` (`v-else-if="splitMode"`, was a bare `v-else`), and added a checkout-button guard for unresolved due + no phone to `Pos/Index.vue` (Clothing's already had it). **Any past sale where this happened cannot be identified from the data alone** — flagged to Khaled directly.
- **Legacy stuck-entry retry gap**: a bill queued before Phase 11 existed had no `failureKind` at all, so the failed-queue sheet's retry button (gated on `failureKind === 'transient'`) never showed for it — only "cancel." Widened to `failureKind !== 'permanent'`, which covers both a genuine transient failure and an unclassified legacy entry.

## Phase 12 — Four offline-POS fixes: navigation, bottom-nav labels, print/WhatsApp, and a real Pending Sales review flow

Full spec from Khaled, four numbered problems. Logic-safe, additive throughout — the shared offline-sale queue (`client_uuid`, tenant scoping) from Phases 1/11 is unchanged underneath all of this.

### 12.1 — Offline navigation showed a confusing generic error
- Root cause (dedicated research): `app.js`'s `router.on('exception', ...)` handler never called `event.preventDefault()` on Inertia's cancelable `inertia:exception` event — so even though it ran and showed its own "📴 নেটওয়ার্ক সমস্যা" toast, Inertia's internals treated the exception as "not handled" and re-threw it as a genuine unhandled promise rejection afterward, which the OTHER, unrelated safety-net handler (`window 'unhandledrejection'`) also caught and displayed — its "কিছু একটা ভুল হয়েছে" toast overwrote the first one within the same tick (`useToast()` holds one shared message ref). Fixed by calling `event.preventDefault()` and picking the message based on `navigator.onLine` (`resources/js/support/navErrorMessage.js`, unit-tested) — offline shows the specific, reassuring message the spec asked for; a rare online-but-failed case keeps the old generic one. A failed Inertia visit never swaps the page anyway (confirmed via Inertia's own source), so "stay on the current page" needed no extra code.
- Caching strategy itself (`public/sw.js`, network-first with cache-fallback for `/app/*`) is unchanged — which pages work offline still depends on having been visited once while online for everything EXCEPT the Sell page (see 12.1b below, added after Khaled hit this live — a cashier sitting on Home when connectivity drops got the correct "needs internet" message, but for the one page offline selling specifically exists for).

### 12.1b — Sell page itself wasn't guaranteed to be cached (found live, same day)
- The opportunistic "cached only if visited while online" rule from 12.1 meant Sell/POS had no special guarantee despite being THE reason offline support exists — reported live: offline on Home, tapping Sell correctly showed the new "needs internet" message, but Sell should essentially never need that message.
- Fixed in `app.js`: `router.prefetch()` (Inertia 2's real prefetch API — a genuine request with correct `X-Inertia` headers, so the service worker's existing fetch handler caches it exactly like a real visit would, not a hand-rolled `fetch()` that would produce a differently-shaped, never-matched cache entry) for whichever route Sell actually points to (`app.pos`, or `app.restaurant.tables.index` for a restaurant shop — same `isRestaurant` check `AppLayout.vue`'s own `mobileSellHref` uses) fires once on every cold app load while online. This tops up Sell's cached copy regardless of which page happens to be open, so it's reachable offline even if the cashier never explicitly visited it this session.

### 12.2 — Bottom-nav label wrapping/misalignment
- Root cause: `.tab span` had no `white-space:nowrap`/`text-overflow:ellipsis` at all, and the mobile bottom nav's two customizable slots reused each sidebar item's FULL label (e.g. `nav.stockFull` = "স্টক ও পণ্য"/"Stock & Products") instead of the already-existing short one (`nav.stock` = "স্টক"/"Stock") that the sidebar itself doesn't need but the nav does.
- Fixed: `.tab { min-width: 0 }` + `.tab span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis }` (min-width:0 is load-bearing — a flex item's default min-width:auto silently defeats ellipsis). Added a `navLabel` field (short variant) to the handful of sidebar items whose full label was too long for the nav — `mobileNavSlots` prefers it when present, sidebar's own label is untouched.

### 12.3 — Print/WhatsApp entirely missing after an offline sale
- Confirmed (dedicated research): `submitCheckout()`'s offline catch branch never set `lastSale`/`receiptOpen` — the cart just reset with a toast, no receipt sheet ever opened, so the existing print/WhatsApp buttons (which only exist inside that sheet) were unreachable. `window.print()` and the WhatsApp message builder are both pure client-side operations needing no server data, confirmed capable of working from a locally-built sale-shaped object.
- `resources/js/support/offlineSaleSnapshot.js` (unit-tested) builds one from the queued payload alone — items/qty/price-snapshot/discount/payments/customer, a stable `OFF-XXXXXXXX` placeholder invoice derived from `client_uuid`, flagged `isOfflinePending: true`. `ReceiptMemo.vue` shows a clear "⏳ অফলাইন বিল — সিঙ্কের পর চূড়ান্ত নম্বর পাবেন" banner when that flag is set. WhatsApp's message-building logic was extracted into `resources/js/support/receiptMessage.js` (previously duplicated separately in `Pos/Index.vue` and `Clothing/Pos.vue` — now both, plus `AppLayout.vue`'s later pending-sales print preview, share one implementation) and guarded against actually being offline (`wa.me` needs a live connection to open at all) with a toast instead of a silent no-op.
- `Clothing/Pos.vue` turned out to have NO `client_uuid`/shop-pin/product-snapshot at all — it predated both Phase 1's idempotency fix and Phase 11's shop-mismatch fix entirely (a real, independently-discovered gap, not something this task introduced). Brought to parity with `Pos/Index.vue` while here, since the shared pending-sales sheet needs to work uniformly regardless of which screen queued an entry.
- Sync's success path (`useOfflineSync.js`) now also writes a local `OFF-XXXXXXXX → INV-YYYY` mapping (capped at the last 20) so a receipt shared before sync can be looked up afterward — shown in the Pending Sales sheet's "সম্প্রতি সিঙ্ক হয়েছে" section.

### 12.4 — No way to review/discard offline sales before they auto-sync (the big one)
- New shop setting `shops.offline_sync_mode` (`'auto'` default — unchanged existing behavior for every shop — or `'review'`, owner-only opt-in via More.vue). `useOfflineSync.js`'s `maybeAutoSync()` (replacing the old direct `trySync()` call from the `online` event / page load) checks it via `shouldAutoSync()` (`resources/js/support/syncModeDecision.js`, unit-tested): review mode never auto-sends, relying on the existing pending-count banner + the sheet's manual "sync now"/"sync all"; auto mode runs a 30-second countdown (dismissible, `autoSyncCountdown`) before actually calling `trySync()`, giving a last look without changing the default behavior's eventual outcome.
- `AppLayout.vue`'s former failed-only sheet now shows EVERY still-unsynced entry (pending and failed), each with: full bill breakdown (reused from Phase 11's `billSummary()`), print/WhatsApp, a status-appropriate sync/retry action, and cancel-with-reason (`cancelWithReason()`, already built in Phase 11 — reused as-is, logs via `Activity::log()`). Reachable from the existing offline banner AND a new 🔄 button on `Pos/Index.vue` itself (`offlineSync.pendingSheetOpen` moved into the composable, module-level, specifically so either opener works).
- Discard permission: `canDiscardEntry()` (`resources/js/support/offlineQueue.js`, unit-tested) — anyone with the shop's `returns` permission can discard any entry; a cashier without it only their own, tracked via `queuedByUserId`/`queuedByUserName` (local-only attribution captured at enqueue time from the logged-in user, never sent to or validated by the server, since this data never reached the server to begin with — a UI courtesy, not a security boundary). An already-synced sale is structurally impossible to see here at all (synced entries are removed from the queue immediately), so "use the normal void/return flow instead" needed only a one-line hint, not enforcement code.
- Cross-shop/cross-session isolation: already covered by Phase 11's shop-pin/`shop_mismatch` work — unchanged, reused.

### 12.5 — Docs
- `docs/OFFLINE_TEST_CHECKLIST.md` updated with manual steps for all four problems (device/timer/DOM behavior that doesn't fit a vitest unit test — consistent with how every other offline-sync phase this session split automated vs. manual coverage).

---

## Definition-of-done checklist (tracked across all phases, per master prompt)

- [ ] Per-business-type + mixed-shop smoke tests all passing (`shop:smoke-test`, Phase 0.6)
- [ ] `shops:repair-defaults --dry-run` run and reported (command does not yet exist — build in Phase 0 alongside 0.1/0.2)
- [ ] No new N+1 queries introduced by any phase
- [ ] Migrations verified both via `migrate:fresh --seed` and against existing data
- [ ] Money/stock reconciliation commands built and dry-run (`due:reconcile` — Phase 3)
- [ ] `docs/ARCHITECTURE_NOTES.md`, `docs/FIX_PLAN.md`, `docs/OFFLINE_TEST_CHECKLIST.md`, CHANGELOG all present
- [ ] Final comprehensive Bengali report for Khaled covering what changed, deploy steps, remaining risks, open questions
