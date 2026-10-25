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

## Phase 13 — Session/419 ("Page Expired") permanent fix — see docs/SESSION_FIX.md

Khaled's own master-prompt numbering calls this "Phase 0.1" — logged here
as 13 instead because this file already has an UNRELATED, pre-existing
"Phase 0 / 0.1-0.6" (ShopProvisioner idempotency, business-type drift —
section starting at line 9) from an earlier master prompt. Same numbers,
completely different work — see docs/SESSION_FIX.md's own note.

Full root-cause writeup, fix list, and deploy notes live in
`docs/SESSION_FIX.md` (Khaled's required structure: root cause with file:
line citations, then the fix, then tests). Short version: the real,
demonstrable root cause is that a prior fix (`ebd1eef`, 2026-08-17) bumped
`config/session.php`'s SESSION_LIFETIME default from 120 to 720 minutes,
but never touched the actual `.env` file (gitignored, never updated by a
deploy's `git pull`) — so the effective session lifetime stayed at 120
the whole time. Combined with every raw-fetch call site (POS checkout,
offline-sync replay) reading its CSRF token from a `<meta>` tag captured
once at page load and never refreshed, a POS tab left open for a normal
shift could easily outlive its own session and hit a 419 with no way to
recover other than reloading the page. Also found and fixed along the
way: `useOfflineSync.js`'s `trySync()` used a bare `fetch()` (no 401/419
retry at all) and classified a 401/419 as `'permanent'` (the generic 4xx
bucket) — almost certainly the real cause of the earlier unexplained "1
sale(s) failed to sync" report with nothing in the server log.

---

## Phase 14 — Phase 3 feature 1: "সিজন ক্যাম্পেইন কিট" (season campaign kit)

**Design note** (written per the master prompt's "আগে ডিজাইন নোট, তারপর
বানাও" rule): a campaign is a saved bundle of an existing `Promotion` +
an existing customer segment filter + a WhatsApp message template, grouped
under an owner-defined `Season` (a name + date window they reuse year to
year — "ঈদুল ফিতর ২০২৭", not a hardcoded holiday list). Nothing new was
built for discounts (`PromotionController`/`Promotion` own that entirely)
or for messaging (the same segment filter + WhatsApp send queue
`CustomerList/Index.vue` already used) — "launching" a campaign just
flips its linked promotion active and opens that same send queue with the
resolved segment + rendered template.

**Reused, not duplicated**:
- `Promotion` model/engine — a campaign's `promotion_id` just points at
  one, created/edited via the existing `PromotionController`.
- Customer segmentation — pulled OUT of `CustomerList/Index.vue`'s own
  inline filter into `resources/js/support/customerSegments.js`
  (`applySegment()`), now shared by both pages instead of existing in
  only one of them.
- WhatsApp sending — `useWhatsappSendQueue.js` (already built for the
  WhatsApp bulk fix), used exactly as-is on the new Campaigns page.

**New, minimal**: `seasons` + `campaigns` tables (both `BelongsToTenant`),
`Season`/`Campaign` models, `SeasonController`/`CampaignController`, a new
`season_campaigns` feature flag (opt-in, gated with the existing
`promotions` permission — not auto-granted to any shop), and
`resources/js/Pages/App/Campaigns/Index.vue`.

**Real bug caught before shipping**: `season_id`/`promotion_id` validated
with a table-wide `Rule::exists(...)` would have accepted another shop's
id (the `exists` rule ignores Eloquent's tenant global scope entirely —
the exact class of bug already fixed once in the offline-sync work).
Scoped both with `->where('shop_id', Tenancy::id())` before this ever
shipped; `test_a_shop_cannot_link_a_campaign_to_another_shops_season_or_promotion`
locks it in.

Tests: `tests/Feature/SeasonCampaignTest.php` (10), `customerSegments.test.js` (7).

## Phase 15 — Phase 3 feature 2: "সিজন স্টক প্ল্যানার" (season stock planner)

**Design note**: no new source of truth — the suggested reorder qty for
each product is just last year's actual `SaleItem` qty for the exact same
date window (shifted back one year) minus the product's current stock,
read live via the same `whereHas('sale', ...)` pattern
`Reports::productStats()` already uses. The owner reviews/adjusts the
suggested qty in the UI, then "convert to PO draft" creates ordinary
`pending`-status `Purchase` rows (the existing purchase-order mechanism,
`PurchaseController::markReceived()` applies stock/money exactly as it
already does for any other pending purchase) — no new purchasing system.

**Reused**: `Purchase` model + its existing pending/received lifecycle,
`Supplier` picker, `Season` (from feature 1).

**New, minimal**: `SeasonController::stockPlan()` (GET, read-only
suggestion) + `::draftPurchases()` (POST, creates the Purchase rows),
`resources/js/Pages/App/Seasons/StockPlan.vue`.

**Deliberately excluded**: a product with size/color variants — its
stock lives per-variant, not on the base product, and
`PurchaseController::markReceived()` hard-aborts a variant product's
purchase unless a specific `product_variant_id` was chosen (which this
planner doesn't ask for). Shipping a draft purchase that's guaranteed to
fail on receipt would be worse than just not suggesting it; flagged as a
known gap rather than guessed at.

**Caught before shipping**: `purchases.qty` is a plain `unsignedInteger`
— a weight-based product's suggestion can be fractional, which would
either truncate silently or error depending on SQL strictness. Rounds
UP (`ceil`) when creating the draft, since under-ordering defeats the
suggestion's whole purpose.

Tests: `tests/Feature/SeasonStockPlanTest.php` (9).

## Phase 16 — real bug found mid-build: `discount_price` never applied at checkout

While designing Phase 3 feature 3 (dead stock clearance — see Phase 17
below), found that `products.discount_price` — settable in
`Stock/Index.vue`'s edit form, shown on barcode labels and in exports —
was never actually read by `PosController::performCheckout()`. A cashier
always charged the full `price` regardless of what was set; the field
was pure decoration. Confirmed with Khaled this was unintentional (not a
"reference-only" field) before fixing, since it's a real money-handling
change for any shop that had already set it.

Fixed: `performCheckout()`'s base-product-line price now resolves to
`discount_price ?? price` (never for wholesale, which already has its
own separate, usually-lower price; never for a pack-unit or variant
line, each priced independently). `BillingPreview::compute()` needed no
change — it already runs the exact same `performCheckout()` logic then
rolls back, so it can never drift from this fix. The frontend cart math
(`Pos/Index.vue`'s `unitOf()`, `Clothing/Pos.vue`'s `lineUnitPrice()`)
and the product-tile price display both needed the identical fix, or
the cashier's on-screen total would disagree with what checkout actually
charges — the exact class of bug `BillingPreview` itself was originally
built to prevent for coupons/loyalty points.

Tests: `tests/Feature/DiscountPriceCheckoutTest.php` (5).

## Phase 17 — Phase 3 feature 3: "ডেড স্টক ক্লিয়ারেন্স" (dead stock clearance)

**Design note**: no new discount mechanism — "মার্কডাউন" sets the
existing `products.discount_price` (now real, per Phase 16 above) in
bulk across however many slow-moving products the owner selects. "Dead"
means no `sale_items` row in the shop-configurable `dead_stock_days`
window (new, same shape as the existing `shops.return_window_days`,
default 60 — no existing shop's behavior changes until they open the
new page). Sorted nearest-expiry-first when `batch_tracking` is on,
matching the master prompt's own ordering requirement.

**Deliberately out of scope**: a "বান্ডেল সাজেশন" (bundle) algorithm —
the existing Promotions page's BOGO/combo tool already covers this need
fully; this page links to it rather than building a second, speculative
bundling engine with no real usage signal yet.

**Deliberately excluded from the dead-stock list**: a product with
size/color variants or `sold_by_weight` set — same reasoning as the
stock planner (Phase 15): a flat `discount_price` on the base product
doesn't unambiguously mean anything for either (a variant's price is
its own; a weighed product's "price" is per base-unit, not per sale
line), so surfacing them here would invite a markdown that doesn't
actually do what it looks like it does.

Tests: `tests/Feature/DeadStockTest.php` (13).

## Phase 18 — Phase 3 feature 4: "দৈনিক মালিক সারাংশ" (daily owner summary)

**Design note**: no new source of truth — every figure (sales, profit,
top/slow product, low-stock count, total due) is read straight from
`Reports::rangeStats()`/`topProducts()`/`bottomProducts()` (the exact
helper every report page already uses) and the same low-stock query
`SendLowStockAlerts` already runs. Delivered purely as an in-app
database notification (reusing the existing `Notification`/bell system
— `LowStockAlert`'s own pattern copied as-is) plus, when the owner has a
phone on file, a `wa.me` link in the notification that opens a chat with
their OWN number prefilled with the summary — a real tap-triggered
`window.open()`, same ground rules as the WhatsApp bulk work
(`docs/WHATSAPP_BULK_PLAN.md`): no paid Cloud API, no automation.

**Reused, not duplicated**: `Reports::*`, the existing `Notification`
bell UI (extended with one new optional WhatsApp button, shown only
when a notification's data carries a `whatsapp_url`), the low-stock
query, the "never alert twice in one day" dedup pattern from
`SendLowStockAlerts`.

**Host constraint respected**: `routes/console.php` already documents
that this host has `proc_open`/`exec` disabled, so every scheduled
command MUST go through `Schedule::call(fn () => Artisan::call(...))`,
never `Schedule::command()` — followed exactly, scheduled `23:30` daily.

Tests: `tests/Feature/DailyOwnerSummaryTest.php` (6).

## Phase 19 — Phase 3 feature 5: "অ্যাডভান্স বুকিং/লে-অ্যাওয়ে" (advance booking/layaway)

**Design note**: deliberately NOT a new table/model. Mechanically, a
layaway IS the existing installment flow — a sale already happened
(stock already decremented at that checkout, which is exactly how
"স্টক reserved" is satisfied: nobody else can buy an item that's already
been sold), a down payment was taken, and `InstallmentPlan` tracks the
rest. The only genuinely new concept is whether the item has physically
been picked up yet — a financed sale's item is normally taken home
immediately; a booked one often sits at the shop until paid off. So
this phase just adds two nullable/defaulted columns to the existing
table (`booking`, `picked_up_at` — both false/null by default, zero
behavior change for every existing plan) and a pickup/undo-pickup
action, reusing 100% of `InstallmentController::store()`'s existing
schedule-building, due-ceiling, and idempotency logic.

**UI simplification for the booking case**: the installment-creation
sheet (`Sales/Show.vue`) hides the multi-installment-count/frequency
pickers when "booking" is checked (a layaway is normally one lump
payment at pickup, not a recurring schedule) and auto-sets
`installment_count = 1`; the owner can still use the full multi-
installment picker if they genuinely want a payment plan AND to mark it
as a reserved item.

Tests: `tests/Feature/AdvanceBookingTest.php` (7) — `InstallmentPlanTest.php`'s
existing 15 re-run unchanged to confirm zero regression on the plain
(non-booking) flow.

---

## Phase 20 — Phase 3 feature 6: "রেফারেল" (customer-to-customer referral)

**Design note**: reuses the existing loyalty-points engine as the only
reward mechanism — no new ledger, no new currency. Two new nullable/
defaulted `customers` columns (`referred_by_customer_id`,
`referral_rewarded_at`) and one new defaulted `shops` column
(`referral_bonus_points`, default 50 — same "sensible preset, not a
forced zero" precedent as `loyalty_earn_rate`/`loyalty_point_value`).

**Abuse prevention** (explicitly required by the master prompt):
- *Self-referral is structurally impossible*: `referred_by_customer_id`
  can only ever be set at `CustomerController::store()` time, before the
  row has an id of its own to match against — there is no edit endpoint
  that could retarget it later, so it can never be pointed at the
  customer's own (not-yet-existing) id.
- *Exactly-once payout per referral*: `referral_rewarded_at` is stamped
  the moment the reward is paid and checked before paying it again — a
  replayed or repeated checkout for the same customer can never pay
  twice.
- *Requires a real purchase, not just a referral record*: the reward
  fires on the referred customer's FIRST real sale
  (`PosController::performCheckout()`'s `$isCustomersFirstSale`, captured
  BEFORE that checkout's own `Sale` row is created — so it can never see
  its own transaction). Paying out at referral-creation time instead
  would be trivially abusable: fake customer records referring each
  other for free points with no purchase requirement at all.
- *Cross-shop referrer injection blocked*: `referred_by_customer_id`'s
  validation uses `Rule::exists('customers','id')->where('shop_id',
  Tenancy::id())`, not a bare `exists:customers,id` — a bare rule is
  table-wide and would let shop A's form reference shop B's customer id
  (same tenant-scoping bug class caught earlier this session in the
  Season Campaign and Stock Planner features).
- *Feature-gated on both flags*: the reward only pays out when both
  `customer_referrals` AND `loyalty_points` are enabled on the shop
  (a shop can have referrals on without loyalty points meaning anything
  — the reward silently does nothing rather than erroring) and
  `referral_bonus_points > 0`.

**New opt-in feature flag**: `customer_referrals` (never auto-granted to
any existing shop, per the master prompt's explicit rule).

Tests: `tests/Feature/CustomerReferralTest.php` (7) — first-sale reward,
no double-reward on a second sale, no reward without a referral on file,
no reward with either feature flag off, cross-shop referrer rejected,
referral recorded correctly at creation.

---

## Phase 21 — Phase 3 feature 7: "ডিজিটাল ক্যাটালগ লিঙ্ক" (digital catalog link)

**Design note**: reuses the existing `Product` model entirely — no new
catalog-specific product table. Two new columns: `products.show_in_catalog`
(default false — the owner opts products in one at a time, nothing
becomes public just because the feature gets granted) and
`shops.catalog_slug` (nullable/unique, lazily assigned the first time
the owner opens the new catalog settings page — a shop that never turns
the feature on never gets one).

**Public page, same pattern as the existing rate/{sale} QR flow**
(`RatingController`): a new top-level (not `App\Http\Controllers\App`)
`CatalogController@show($slug)` reached at `GET /catalog/{slug}`, no
auth, no signature (unlike the rate link, this is meant to be shared
freely on WhatsApp/Facebook — `{slug}` is a random token, not a
sequential id, so there's nothing to enumerate). `Shop`/`Product` are
tenant-scoped models but there's no authenticated shop context on a
public request, so the controller explicitly bypasses the global scope
the same way `RatingController` already does — `$shop->products()`'s
own `shop_id` foreign-key constraint still fully isolates one shop's
catalog from another's regardless.

**Never exposes cost price or any other money-in figure** — the public
response only ever carries `id/name/emoji/photo_url/price/
original_price/in_stock`, never `cost`, `wholesale_price`, or the raw
`stock` count (only a boolean in/out-of-stock flag). `price` resolves
`discount_price ?? price` so the catalog always matches what checkout
would actually charge (same discount_price fix as Phase 16).

**Owner-side control panel**: a new `App/Catalog/Index.vue` page
(`CatalogSettingController@index`, gated `perm:stock` +
`feature:digital_catalog`) shows the public link (copy/WhatsApp-share
buttons) and every product with a one-tap visibility toggle
(`ProductController::updateCatalogVisibility`, same lightweight-PATCH
pattern as the existing `products/{product}/availability` toggle).

**New opt-in feature flag**: `digital_catalog` (never auto-granted).

Tests: `tests/Feature/DigitalCatalogTest.php` (9) — only visible
products listed, cost price never exposed, discount price /
out-of-stock reflected correctly, 404 without the feature granted, 404
for an inactive shop, 404 for an unknown slug, settings page lazily
assigns a slug, owner can toggle visibility, cross-shop toggle rejected
(404, route-model-binding tenant scoping).

---

## Phase 22 — Phase 3 feature 8: "ক্রেডিট লিমিট" (customer credit limit)

**Design note**: reuses the existing `customers.due`/checkout-credit
flow entirely — no new ledger, no separate "credit account" concept.
Two new nullable columns, both defaulting to null (= no limit, today's
exact unlimited-due behavior, unchanged even once the feature is
granted until the owner actually sets a number):
`shops.default_credit_limit` (shop-wide fallback) and
`customers.credit_limit` (per-customer override — wins over the shop
default when set; `Customer::effectiveCreditLimit()` resolves which
one actually applies).

**Hard block, not just a warning, at the point real money is on the
line**: `PosController::performCheckout()` checks, right alongside the
existing "due needs a customer phone" guard, whether
`customer.due + this sale's new due` would cross the effective limit,
and `abort(422, ...)` if so — the same guard pattern every other
money-safety check in this controller already uses (loyalty-point
over-redemption, over-tender, etc.). The **warning** half of the master
prompt's "warning/block" ask lives in the UI instead: Pos/Index.vue's
customer-lookup panel shows the customer's current due against their
effective limit (and how much room is left) as soon as their phone/name
resolves, before checkout is ever attempted — so a cashier sees it
coming rather than being surprised by a 422.

**Where it's configured**: the shop-wide default lives in
Accounts/Index.vue alongside the other money-policy settings
(VAT/service charge, same page, same pattern). The per-customer override
is a quick-edit sheet on the Due page's (`Customers/Index.vue`)
collect-money action row.

**New opt-in feature flag**: `credit_limit` (never auto-granted).

Tests: `tests/Feature/CreditLimitTest.php` (10) — blocked once a
customer's own limit would be exceeded, allowed while under it, shop
default applies without a customer override, customer override wins
over the shop default, no block with no limit set anywhere, no block
without the feature granted, a partial cash tender still counts the
remaining due against the limit, owner can set the shop default, owner
can set a customer's own limit, cross-shop set rejected (404,
BusinessScope).

---

## Phase 23 — Phase 3 feature 9: "ধরন-ভিত্তিক সুবিধা" (business-type-specific features)

**Scope clarified with Khaled** (this item was ambiguous on its own —
`config/business_types.php` already does provisioning-time
category/unit/field/feature-default seeding per vertical, which could
have been mistaken for "done"): he confirmed he wants genuinely NEW,
small, concrete features added per vertical, not a restatement of that
existing provisioning infrastructure. Two picked, after checking what's
already covered (clothing's per-variant low-stock alert and bulk
variant stock-in, restaurant's tables/KOT/toggle-availability, and
mobile's on-demand IMEI/warranty lookup via SerialLookupController all
turned out to already exist — ruled out as "already covered" rather
than rebuilt):

1. **Pharmacy — automatic generic substitute suggestion.** A cashier
   could already manually search by generic name (`Pos/Index.vue`'s
   `filtered` computed already matches `generic_name`), but nothing
   surfaced an alternative automatically. Now, an out-of-stock
   product's own tile shows an in-stock alternative sharing its
   `generic_name` right there, one tap to add it instead — pure
   client-side (`genericAlternative()`), since `Pos/Index.vue` already
   loads the shop's full product list. No backend change at all.

2. **Mobile/electronics — proactive warranty expiry alerts.** The
   lookup screen only ever answered "is THIS specific IMEI still under
   warranty" when someone typed it in; nothing told the owner ahead of
   time. `SendWarrantyExpiryAlerts` mirrors `SendExpiryAlerts`' own
   30/7/0-day tiered, dedup-by-tier structure exactly, just checking a
   sold `ProductSerial.warranty_expiry` instead of a batch's
   `expiry_date` — same `Notification` (database channel), same
   `Schedule::call()` registration pattern (`routes/console.php`,
   `dailyAt('09:00')`, this host's `proc_open`-disabled constraint).

**New opt-in feature flags**: `generic_substitute_suggestions`,
`warranty_expiry_alerts` (neither auto-granted).

Tests: `tests/Feature/WarrantyExpiryAlertTest.php` (4) — tightest tier
per serial, already-expired gets the 0-day tier, no alert without the
feature, no duplicate alert for the same tier. The generic-substitute
suggestion is pure client-side template logic reading already-loaded
props (same category as other feature-flag-gated UI in this codebase,
e.g. `hasLowStockAlerts`'s own tile styling) — no new backend surface
to test.

---

## Phase 24 — Phase 3 feature 10: "অ্যাম্বাসেডর টুলস" (ambassador tools)

**Scope clarified with Khaled**: the master prompt's full list (lead
tracker, referral code, demo mode, pitch calculator, WhatsApp signup
link, seasonal offers) would require ambassadors to have their own
login/portal — a genuinely new authentication surface and a large
scope on its own. He confirmed the smallest real piece instead: just
the referral code + signup link, so a shop that signs up through it
gets credited automatically instead of needing an admin to manually
tie `brought_by_ambassador_id` together afterward.

**Design note**: `ambassadors.referral_code` (nullable/unique) is
generated once, automatically, at `Admin\AmbassadorController::store()`
time — derived from the employee ID (already effectively unique) with
a random-suffix fallback only on a genuine collision. Existing
ambassadors (created before this migration) are backfilled the same
way directly in the migration's `up()`, so every ambassador — old or
new — has a working link with no extra admin step.

`Ambassador::signupUrl()` is just `route('signup').'?ref='.code`.
`SignupController::create()` passes `?ref=` through as an Inertia prop;
`Signup.vue` carries it as an invisible, uneditable field on the
existing form (`form.ref`) so it survives a validation-error round
trip, not just the first page load. `SignupController::store()` looks
up an **active** ambassador by that code — a bad/stale/unknown code is
never a hard validation error (the shop owner has no way to know their
code didn't resolve and must not be blocked from signing up over it) —
and, when found, applies the exact same two effects
`Admin\AmbassadorController::assignToShop()` already applies when an
admin manually sets `brought_by_ambassador_id`: the one-time bonus
commission row (`AmbassadorCommissionService::handleBroughtByChange()`)
and becoming the shop's currently-assigned ambassador, which drives the
recurring-%  commission on every future subscription payment
(`AmbassadorCommissionService::assign()`). A self-signup via referral
link is credited exactly as much as if an admin had done it by hand.

Admin/Ambassadors/Index.vue and Show.vue both show the referral code
and a one-tap copy-to-clipboard for the signup link, so Khaled/his team
can hand it to an ambassador to share on WhatsApp themselves.

Tests: `tests/Feature/AmbassadorReferralTest.php` (6) — a new
ambassador auto-gets a code, signing up through a valid code credits
both the one-time bonus and the recurring assignment, an unknown code
still lets signup succeed (just uncredited), no `ref` at all still
works exactly as before, an *inactive* ambassador's code does not
credit them, the signup page's `ref` prop passes the query param
through correctly.

**Everything else on the master prompt's list for this item (lead
tracker, demo mode, pitch calculator, seasonal offers) is explicitly
deferred** — each would need its own design discussion once Khaled
decides whether ambassadors get a real self-serve portal at all.

---

## Phase 3 complete — all 10 features shipped

Every item on the master prompt's Phase 3 list (season campaign kit,
season stock planner, dead stock clearance, daily owner summary,
advance booking/layaway, customer referrals, digital catalog link,
credit limit, business-type-specific features, ambassador tools) is
now designed, built, tested, and deployed. Per the master prompt's own
rule, **Phase 4 (enterprise gap-filling) is explicitly gated on
Khaled's approval** and has not been started.

---

## Phase 25 — small fix: attach a customer to a service ticket after intake

Khaled approved clearing the standing decision items — this one (see
`docs/ENTERPRISE_GAP_REPORT.md`'s "সার্ভিস টিকিটে পরে কাস্টমার যুক্ত করা"
gap) was explicitly flagged there as small and doable immediately, so
it went first.

**Design note**: `ServiceTicketController::store()` only ever captured
a customer at intake time (`customer_phone`); `bill()`'s own "বাকি
রাখতে হলে কাস্টমারের তথ্য দিন" guard had no way to be satisfied
afterward for a walk-in device nobody got a phone number from up
front. New `updateCustomer()` method, same find-or-create-by-phone as
`store()` (also doubles as a way to correct a typo'd phone/name on an
already-attached customer), blocked once the ticket is
`delivered`/`cancelled` — same guard every other mutating method on
this controller already has. `ServiceTickets/Show.vue` gets an "👤
কাস্টমার যুক্ত করুন" button when none is attached yet, or a small
"এডিট" link next to the existing customer line when one already is.

**Also checked, no action needed** — the other two small decision
items from the same report section:
- Admin Ambassador Edit page already has both `one_time_bonus` and
  `recurring_percent` fields (`Admin/Ambassadors/Show.vue`, built in
  an earlier session) — decision #3's main question was already
  answered in practice. There is also no delete action for an
  ambassador at all right now, so its hard-vs-soft-delete sub-question
  doesn't currently apply to anything.
- The Android APK rebuild reminder (decision #4) isn't a code task —
  it's tracked against the separate TWA-replaces-Capacitor plan.

Tests: `tests/Feature/ServiceTicketTest.php` gains 4 — a customer can
be attached to a walk-in ticket, attaching reuses an existing customer
by phone rather than duplicating, a ticket with a customer attached
after the fact can then bill on credit (previously impossible), and
attaching is blocked on an already-delivered ticket.

---

## Phase 4 begins — Khaled's decisions

Khaled approved starting Phase 4 and gave two specific calls:
1. **Start with item #1** (shared catalog + branch transfer accept/
   reject) — `docs/ENTERPRISE_GAP_REPORT.md`'s own recommended first
   item, since #2 (PO/GRN) and #3 (branch price lists) both depend on
   it.
2. **Build the full Wholesale system** (tiered pricing, MOQ, wholesale
   customer groups, separate invoices) — not the lighter "just tiered
   pricing" option.

Per the master prompt's own "stop after each phase" rule, and because
item #1 genuinely touches the core Product data model (unlike every
Phase 3 feature, which was additive and narrowly scoped), this is being
built as its own run of sub-phases — same test/build/commit/push/
deploy discipline as Phase 3, one deployable piece at a time, not one
giant commit.

---

## Phase 26 — Phase 4 item #1, part A: real-time shared catalog sync

**Important finding before building anything**: a "shared catalog"
mechanism already exists — `App\Support\CatalogSync` (used by
`Admin\ShopController::createBranch()` at branch creation, and by
`BranchController::syncCatalog()` as an owner-triggered manual
re-sync button) already copies a main shop's products/categories/
units into every branch, matched by barcode-then-name, explicitly
never touching a branch's own `stock`. This already satisfies most of
what `docs/ENTERPRISE_GAP_REPORT.md`'s #1 finding describes — the
report rated it insufficient for exactly two reasons: (a) it's
**manual** (the owner has to remember to press a button — a price
change doesn't reach branches until they do), and (b) matching is
**purely by barcode/name**, which silently breaks (creates a duplicate
row instead of updating the existing one) if either side is ever
renamed without a barcode set. Both are fixable by extending
`CatalogSync`, not by replacing the per-branch-Product-row model the
rest of the app (POS, sales, reports, stock, promotions — everything)
already depends on — a from-scratch "shared SKU master" table would
have meant touching dozens of files for a worse-understood payoff.

**Design**:
- New column `products.master_product_id` (nullable, self-referential
  FK, `nullOnDelete()`) — sets an explicit, durable link the first
  time a branch row is ever matched to a main-shop row (by the
  existing barcode/name fallback), so every sync after that finds it
  by id instead of re-matching by name — survives a rename on either
  side from then on. Nullable and unused by every single-shop business
  and every multi-branch business not using this feature — zero
  behavior change on its own.
- New opt-in feature flag `shared_catalog`, granted to a **main shop
  only** (`parent_shop_id` null) — its branches don't need the flag
  themselves, they just receive what the main shop pushes.
- `CatalogSync::syncProductToBranches($mainShop, $product)` — a new,
  product-scoped counterpart to the existing full `syncToBranch()`,
  reusing its exact matching/update logic (refactored into shared
  `upsertBranchProduct()`/`findExistingBranchProduct()` methods so
  there's only one place that decides what propagates). Deliberately
  product-only, no variant sync — keeps a single product save cheap;
  variant changes still go out on the next manual "ক্যাটালগ সিঙ্ক করুন"
  press, unchanged from before.
- `ProductController::store()`/`update()` call this automatically,
  right after saving, when the acting shop is a main shop with
  `shared_catalog` on — this is the actual "real-time" part the manual
  button never was.
- A branch's own linked copy (`master_product_id` set) has its shared
  fields (name/price/category/barcode/etc.) effectively owned by the
  main shop from then on: `ProductController::update()` silently drops
  any of those fields from a branch-side edit of a linked product,
  keeping only what's always supposed to stay local (`stock`,
  `reorder_point`, `discount_price`) — `Stock/Index.vue`'s edit sheet
  shows a banner explaining this, so it's not a silent surprise.
- Deliberately NOT built in this part: per-product opt-out ("share
  everything except this one"), a visible "🔗 shared, N branches" badge
  on the main shop's own product list, and the branch-transfer
  accept/reject half of item #1 — that's a separate, unrelated change
  (doesn't touch Product at all) and lands as its own part B.

**Real bug found and fixed while testing**: `CatalogSync::upsertBranchProduct()`
reads `emoji`/`requires_prescription`/`sold_by_weight` straight off the
source `Product` instance — fine for a real product (always created via
`ProductController::store()`, which explicitly sets all three), but a
`Product` instance built any other way (tests, future console
tooling) never reflects a NOT-NULL column's DB-level default (a
migration `->default(...)` only applies when the column is omitted
from the INSERT entirely, not when the in-memory attribute is read back
as null) — propagating that null to the branch insert violated the
same NOT NULL constraint. Fixed with an explicit fallback for each of
the three (`?: '📦'` / `?? false` / `?? false`) rather than trusting
every future caller to always go through the controller.

Tests: `tests/Feature/SharedCatalogTest.php` (7 new) — saving a
product on the main shop propagates to every branch in real time
(each branch copy starting at stock 0), editing the main shop's
product updates every linked copy, a branch cannot change a linked
product's shared fields locally (only stock/reorder_point/discount_price
survive), matching survives a rename once linked (no duplicate
created), no propagation without the feature granted, a branch's own
locally-created product never propagates anywhere, a single-shop
business sees zero behavior change. All 37 pre-existing branch tests
(`BranchStockTransferTest`, `BranchFeatureInheritanceTest`,
`BranchCustomerSharingTest`, `BranchCrossReturnTest`,
`BranchSwitchingTest`) re-run clean — the `CatalogSync` refactor is
fully backward-compatible.

---

## Phase 27 — Phase 4 item #1, part B: branch transfer accept/reject

**Important constraint**: `StockTransferController`'s own docblock
records that Khaled explicitly asked for transfers to be instant, with
no pending/confirmation step ("real time sync hoi moto instant"). The
gap report's complaint (no accept/reject means no protection against a
shortage/mis-posting dispute) is real for a bigger chain, but this must
NEVER become the default for a shop that never asked for it. So this
ships as a new opt-in feature, `transfer_confirmation`, granted to a
business's root shop (same convention as `shared_catalog`) — every
shop without it sees the exact same instant behavior as before, byte
for byte.

**Design**: when confirmation is required, a transfer becomes a true
"in-transit" state rather than an instant move — the source's stock
decrements immediately (the units have left that shelf), but the
destination's stock does NOT increment until the destination branch's
owner explicitly accepts. This models the physical reality a chain
actually cares about (the units are genuinely absent from both counts
while in transit) and is what gives the accept step its real value — a
destination owner who finds a box short can reject rather than having
already been credited stock they never received.

- New `stock_transfers.status` enum (`completed` | `pending` |
  `rejected`), defaulting to `completed` — every existing row, and
  every new transfer for a shop without the feature, is `completed`
  immediately exactly as before. Only a confirmation-required
  transfer is ever created as `pending`.
- Scoped to **plain and variant lines only** — a serial-tracked or
  batch-tracked transfer always stays instant/`completed` regardless
  of the feature, even for a shop that has it on. Deferring a specific
  IMEI's ownership change, or a FEFO batch split, to an accept step
  would need meaningfully more design than this phase's scope — a
  deliberate v1 limitation, not an oversight.
- `StockTransferController::accept($transfer)` / `::reject($transfer)`
  — both owner-only, both reachable only by someone on the
  **destination** shop, both only valid while `status === 'pending'`.
  `accept` increments the destination product/variant now (the
  "received" moment) and marks `completed`; `reject` returns the held
  stock to the **source** product/variant and marks `rejected` — never
  silently lost from either side.
- `StockTransfers/Index.vue` gets an "অপেক্ষমাণ ট্রান্সফার" section
  (visible only on the shop actually named as `to_shop_id`) listing
  every pending transfer awaiting that branch's own accept/reject.

**Real bug found and fixed while testing**: `accept()`/`reject()`'s
Product/ProductVariant lookups didn't bypass `BelongsToTenant`'s
global scope — harmless for `accept()` (the destination row happens
to sit in the SAME shop as whoever's allowed to call it), but
`reject()`'s target is the SOURCE product, a different shop than the
acting (destination) user's own tenancy; the unscoped query silently
matched nothing and the stock return no-op'd via the null-safe `?->`.
Fixed by using `withoutGlobalScopes()` consistently in both methods,
not relying on the coincidence.

Tests: `tests/Feature/BranchTransferConfirmationTest.php` (8 new) —
a plain transfer holds both sides' stock until accepted, accepting
credits the destination, rejecting returns stock to the source, the
source branch cannot accept its own outgoing transfer, an
already-completed transfer cannot be accepted twice, a variant
transfer holds the same way, a batch-tracked transfer stays instant
even with the feature on, and a shop without the feature stays
instant exactly as before. All 15 pre-existing `BranchStockTransferTest`
cases re-run clean.

---

## Phase 28 — Phase 4 item #2: real PO/GRN

**Research first**: `Purchase` (`app/Models/Purchase.php`,
`PurchaseController.php`) is exactly as the gap report described — one
row = one product/variant/ingredient + one qty + one amount, a binary
`pending`/`received` status, no PO number, no multi-line, no partial
receiving. It's also deeply woven into the rest of the app: `Supplier`
payable increments, batch/serial receiving (`BatchStock`/
`SerialStock`), `Reports`, `SupplierController`'s ledger, Admin
exports. Retrofitting multi-line/partial-receiving into it directly
would risk every one of those — so this ships as a **wholly new,
parallel system** (`PurchaseOrder` + `PurchaseOrderItem`) that coexists
with the existing `Purchase` flow untouched; a shop that never turns
on `purchase_orders` sees zero change, and the simple single-line
"record a purchase" flow (`Purchases/Index.vue`) keeps working exactly
as it always has for shops that don't need a full PO workflow.

**Design, modeled closely on the existing `Quotation`/`QuotationItem`
pair** (same header + line-items shape, same shop-scoped sequential
numbering pattern as `invoice_counter`/`quotation_counter`):

- `shops.po_counter` (unsigned int, default 1000) — `po_number` is
  `'PO-'.++$shop->po_counter`, generated under the same locked-shop-row
  pattern `QuotationController::store()` already uses for `quote_no`.
- `purchase_orders`: `supplier_id` (required — unlike a plain
  `Purchase`, a PO is inherently tied to a specific supplier),
  `po_number`, `date`, `expected_date`, `status` (`ordered` |
  `partially_received` | `received` | `cancelled`), `subtotal`,
  `notes`, `user_id`.
- `purchase_order_items`: `product_id`/`product_variant_id` (nullable,
  snapshot `product_name`/`variant_label` survive either being
  renamed/deleted later, same reasoning `StockTransfer` already uses),
  `qty_ordered`, `qty_received` (default 0 — the running total GRN
  tracker), `cost` (the agreed unit cost at order time).
- **Placing a PO moves no stock or money** — same "nothing happens
  until received" principle the existing `Purchase` 'pending' status
  already uses. `PurchaseOrderController::receive()` is the actual
  GRN: given a subset of line items and how much of each arrived
  *this time* (never more than what's still outstanding), it applies
  `StockIn::apply()`/variant-stock increment per line at the PO's
  original agreed cost, sums one lump cash/bank decrement or supplier
  payable increment for the whole receipt event (not one per line —
  matching how a real goods receipt against an invoice is one
  transaction), bumps each line's `qty_received`, and recomputes the
  PO's own status (`received` once every line is fully in,
  `partially_received` if only some is). A PO can only be `cancel()`'d
  while nothing has been received yet.
- **Deliberately out of scope for v1**: receiving at a different cost
  than ordered (price variance — none of this app's existing books
  have a place to post the difference to), a separate GRN document/
  log table (the running `qty_received` total plus `Activity::log()`
  per receipt is enough to answer "how much is left" and "who
  received what, when" without a new table), and batch/serial
  receiving against a PO (a shop needing that level of detail per
  receipt can still use the existing single-line `Purchase` flow for
  those products).
- New opt-in feature `purchase_orders` (never auto-granted), reusing
  the existing `perm:purchases` permission.

Tests: `tests/Feature/PurchaseOrderTest.php` (10 new) — placing a
multi-line PO moves no stock/money, a partial receipt updates stock
and supplier payable proportionally and leaves the PO
`partially_received`, receiving the rest marks it `received`, cannot
receive more than what remains on a line, a cash receipt decrements
`cash_balance` instead of the supplier's payable, a variant line
receives onto the correct variant, cancelling an untouched PO works,
cannot cancel one that's already received something, tenant scoping,
every route 403s without the feature granted.

---

## Phase 29 — Phase 4 item #3: branch/channel price lists

**Research first, re-reading the gap report's own #3 section with
Phase 4 item #1 (shared catalog) already shipped**: its "নেই" list
actually bundles three distinct complaints, and shared catalog already
resolves the root cause of the first:

1. *"No branch/channel price list, because every branch has its own
   Product row"* — the root cause (no linkage between a branch's row
   and the main shop's) is exactly what Phase 26 fixed. A price now
   propagates identically to every branch by default. The remaining
   question is whether a branch can still deliberately charge
   something different — and it already can: Phase 26 deliberately
   kept `discount_price` branch-local-editable (for the dead-stock-
   clearance use case), and checkout/POS/the digital catalog already
   all prefer `discount_price ?? price` uniformly (the Phase 16 fix).
   A branch that genuinely needs its own everyday price can already
   set it via `discount_price` today. Building a second,
   parallel "price override" field purely to rename this same
   mechanic would be exactly the kind of duplicate source of truth
   the master prompt's own ground rules warn against — so this is
   marked **already covered**, not rebuilt.
2. *"No scheduled markdown engine — `discount_price` is a static
   field, not date-windowed like `Promotion`"* — real, medium-sized,
   genuinely still open. **Deliberately deferred** (not pulled into
   this phase) to keep item #3 shippable; noted here so it isn't lost.
3. *"No cross-branch promotion pooling — `Promotion` is shop-scoped,
   so a chain-wide offer has to be recreated on every branch by
   hand"* — real, not covered by anything shipped so far, and the
   natural companion to shared catalog's own shape. **This is what
   item #3 actually builds.**

**Design**: mirrors shared catalog's own pattern closely —
`promotions.master_promotion_id` (nullable, self-referential FK,
`nullOnDelete()`), a new opt-in `shared_promotions` feature (main shop
only), propagation on save. **Scoped to `type = 'coupon'` only** —
a BOGO promotion references `buy_product_id`/`get_product_id`, which
would need the same per-branch product-id mapping `CatalogSync`
already does for `StockTransfer`'s destination matching; deferring
that mapping keeps this phase's risk down, and a chain-wide discount
*code* ("EID10" valid everywhere) is the far more common real request
anyway — a BOGO still works exactly as before, purely shop-local,
regardless of the feature.

- `PromotionController::store()` — a main shop with the feature on,
  creating a coupon, also creates a linked copy on every branch
  (same code/discount_type/discount_value/min_purchase/usage_limit/
  dates, `used_count` always starts at 0).
- `::update()` — editing the master propagates every field except
  `used_count` to each linked branch copy (never overwritten — same
  "never touch what's branch-local" rule as `CatalogSync`'s own
  exclusion of `stock`). Editing a **branch's own linked copy**
  silently keeps only `active` (so one branch can pause/resume
  locally, e.g. it's run out of the deal) — everything else is
  dropped, same defense-in-depth lock Phase 26 already applies to a
  linked `Product`.
- `::destroy()` — deleting the master also deletes every linked
  branch copy (a hard delete, matching `Promotion`'s own existing
  hard-delete behavior — no soft-delete lifecycle to preserve).
  A branch cannot delete its own linked copy at all; must come from
  the main shop.
- **No change needed in `PromotionEngine::resolveCoupon()`** — a
  branch's linked copy is a real row with its own `shop_id`, found by
  the exact same tenant-scoped `code` lookup checkout already does;
  propagating as real rows (not a cross-shop reference) is what makes
  this a zero-touch change for the checkout path, exactly like shared
  catalog needed no change to `PosController` either.

Tests: `tests/Feature/SharedPromotionTest.php` (8 new) — creating a
coupon on the main shop propagates to every branch, editing the main
coupon updates every linked copy, a branch's own `used_count` is never
overwritten by a sync, a branch can only toggle `active` on its own
linked copy (everything else silently ignored), a branch cannot delete
its own linked copy, deleting the master deletes every linked copy, a
BOGO promotion never propagates even with the feature on, no
propagation without the feature granted. All 22 pre-existing
`PromotionTest` cases re-run clean. No frontend change needed —
propagation is fully automatic from the existing create/edit/delete
flow, nothing new for the owner to operate.

---

## Incident — live deploy failure: `features.description` too long

Real production incident (2026-10-21), caught the moment Khaled ran
the deploy: `php artisan db:seed --class=FeatureSeeder --force` threw
`SQLSTATE[22001]: String data, right truncated` on the `shared_catalog`
row. Root cause: `features.description` is a plain `string`
(`VARCHAR(255)`), and three of this session's own newer descriptions
(`shared_catalog` 259 chars, `transfer_confirmation` 306,
`shared_promotions` 256) ran past that limit — never caught locally
because the entire test suite runs on sqlite, which never enforces a
`VARCHAR` length at all, so 900+ passing tests gave no signal; it only
surfaced against the real MySQL column on deploy.

**Root-cause fix, not a one-off trim of those three strings**:
widened `features.description` to `TEXT` (no practical length cap), so
no future description — however long — can ever hit this again. A
raw `ALTER TABLE ... MODIFY description TEXT NULL`, not
`Schema::table()->change()` — this codebase has never used `->change()`
anywhere and that needs `doctrine/dbal`, which isn't a declared
dependency here; a plain statement needs nothing new installed. The
migration explicitly no-ops on a non-MySQL connection (sqlite has no
`MODIFY COLUMN` syntax at all, and nothing to widen there anyway),
so the test suite's own migrations stay unaffected.

Nothing else from this deploy was lost — all 4 pending migrations had
already applied successfully before the seeder step failed; only the
`FeatureSeeder` run needs re-running after this fix lands, which
re-upserts every feature (including the 3 that never got inserted)
in one pass.

---

## Incident — admin impersonation bounced to login on an inactive shop (confirmed fixed by Khaled, 2026-10-21)

Khaled reported (2026-10-21): from Admin, entering a shop (via the
"Impersonate" button) and clicking POS kept bouncing back to the login
form. No live repro access and `storage/logs/laravel.log` couldn't
help diagnose it directly — a plain `redirect()` is normal control
flow, never an exception, so neither candidate middleware
(`EnsureShopUser`, `CheckSubscriptionActive`) ever logged anything
about it.

**Most likely root cause, reasoned from the code rather than a
confirmed live trace**: `CheckSubscriptionActive` runs on every
`app.*` request (`Tenancy::shop()`'s `isActive()` — `status` +
`subscription_expiry`) and, on a failing shop, destructively logs the
session out and redirects to `/login` — correct for a real paying
customer, but an admin impersonating a demo/inactive shop (for support
or to show a prospect around) would trip this exact check every single
time, since impersonation logs the admin in AS the shop's real owner
with no special status. This fits the reported symptom closely:
entering the shop (the one request right after `ImpersonationController
::start()`'s redirect) can still land on Home before the next request
re-evaluates the check, while a deliberate second navigation (POS)
re-triggers it — and "repeatedly" is exactly what re-triggering on
every subsequent request looks like.

**Fix**: `CheckSubscriptionActive` now bypasses the active-shop check
entirely whenever `session('impersonating_admin_id')` is set — an
admin's impersonated session already bypasses every permission/role
gate by virtue of being the shop's own owner (`EnsureUserPermission`'s
"owner always passes"); this makes the subscription gate consistent
with that. A real shop user's own session is completely unaffected —
the bypass only ever applies to a session `ImpersonationController
::start()` itself marked.

Also added temporarily, since the diagnosis wasn't 100% confirmed from
a live trace at the time: `Log::warning()` calls in both
`EnsureShopUser` and `CheckSubscriptionActive`. Khaled deployed,
retested the exact same steps (admin → impersonate → POS), and
confirmed it fixed — both temporary logs have been removed again.

Tests: `tests/Feature/SubscriptionExpiryTest.php` gains 2 — an
impersonating admin's session bypasses the subscription check on an
inactive shop, and can reach POS specifically on one. Every pre-
existing case in that file (expired-shop login rejection, session
termination on next request, JSON 401 vs redirect, poll-vs-plain-
navigation) re-runs unaffected — the bypass only activates when
`impersonating_admin_id` is actually set, which none of those do.

---

## Phase 30 — Phase 4's last approved item: the full Wholesale system

Khaled explicitly approved the FULL system (not the lighter "just
tiered pricing" option) — tiered pricing by quantity, MOQ, wholesale
customer groups, separate wholesale invoices. Built as three
serially-shipped parts, each additive on top of the existing flat
`wholesale_pricing` feature (`products.wholesale_price` + the
whole-cart `sale_type` toggle), which stays completely unchanged for
any shop that only ever wanted that simple version — every part below
gates behind a NEW feature, `wholesale_tiers`, layered on top of (not
replacing) `wholesale_pricing`.

### Part A — tiered quantity pricing + MOQ

- New `product_price_tiers` table (`shop_id`, `product_id`, `min_qty`,
  `price`) — a product can have several tiers (e.g. 1-9 units @ ৳20,
  10-49 @ ৳18, 50+ @ ৳15). At wholesale checkout, the line's own qty
  picks the richest tier whose `min_qty` it clears; a product with no
  tiers defined falls straight back to the existing flat
  `wholesale_price` (or retail, if that's unset too) — zero change for
  a shop using only the simple flat price.
- MOQ is a **hard gate, not a soft fallback**: `products.wholesale_moq`
  (nullable) — at wholesale checkout, a line below its product's own
  MOQ is rejected outright (clear error naming the product and the
  minimum), not silently sold at retail. A product with no MOQ set has
  none enforced, matching today's behavior exactly.
- Both are per-product, additive, opt-in — a shop that never defines a
  tier or MOQ for a given product sees that product behave exactly as
  the existing flat-price mechanism already does.

### Part B — wholesale customer groups

- `customers.is_wholesale` (boolean, default false) — marks a
  customer as a standing wholesale account. Scoped deliberately
  narrow: a binary flag that auto-defaults `sale_type` to `wholesale`
  at checkout once that customer is attached (still manually
  overridable, never forced) — not a speculative multi-tier customer-
  group hierarchy with its own separate rate schedules, which nothing
  in the ask specifies beyond the phrase itself. A customer without
  this flag behaves exactly as today.

### Part C — separate wholesale invoice numbering

- New `shops.wholesale_invoice_counter` (default 1000, same pattern as
  `invoice_counter`/`quotation_counter`/`po_counter`). A wholesale sale
  (under `wholesale_tiers`) gets `'WS-'.++counter` instead of
  `'INV-'.++counter` — a retail sale, and a wholesale sale on a shop
  that only has the base `wholesale_pricing` feature (not the fuller
  `wholesale_tiers`), both keep the exact `INV-` series unchanged.

**New opt-in feature**: `wholesale_tiers`, requires `wholesale_pricing`
already granted (checked, not just assumed) — never auto-granted.

**Part A — shipped 2026-10-21.** `product_price_tiers` table +
`products.wholesale_moq`; `Product::priceTiers()`/`wholesaleTierPriceForQty()`;
`PosController::performCheckout()` resolves the richest tier the line's
qty clears (falling straight back to flat `wholesale_price`/retail when
none applies) and hard-rejects any wholesale line below its product's own
MOQ (abort 422, naming the product and the minimum) before anything is
written. `wholesale_tiers` feature added to `FeatureSeeder`. Branch sync:
`CatalogSync` now also carries `wholesale_moq` and diffs/syncs each
product's tier rows to every branch (matched by `min_qty`, since there's
nothing else to match a tier by) — same main-shop-owns-it rule as
`wholesale_price` already follows; a branch's own product edit still
can't touch either. Frontend: Stock/Index.vue gets an MOQ input and an
add/remove tier-row list (gated on `wholesale_tiers`), synced in
`ProductController::store()/update()` via a full replace-the-rows helper
(skipped for a branch's own linked copy, same as every other main-shop-
owned field). Tests: `tests/Feature/WholesaleTiersTest.php`, 9 passing —
richest-tier selection, fallback below the lowest tier, no-tiers-defined
parity with the flat price, feature-gating, MOQ hard-reject/allow/retail-
exempt/unset-means-none, and tenant scoping.

**Part B — shipped 2026-10-21.** `customers.is_wholesale` (boolean,
default false) — a binary flag, not a multi-tier customer-group
hierarchy (nothing in the ask specified rate schedules per group beyond
the phrase itself, same scope-discipline reasoning as cross-branch
promotion pooling scoped to coupon-type only). `CustomerController`:
`store()` accepts it; new `updateWholesale()` (gated by `feature:
wholesale_pricing`, same pattern as `updateCreditLimit`) is a quick
toggle on the Due/Customers page; `lookup()`/`search()` both now return
it so the POS checkout sheet's phone-lookup and name-search can read it.
Frontend: Due/Customers/Index.vue gets a checkbox on the add-customer
sheet and a toggle button per customer row; Pos/Index.vue's phone-lookup
watcher and name-search picker both auto-set `saleType.value =
'wholesale'` the moment a flagged customer is attached — a preset, not a
lock, the cashier can still click back to retail for that one sale.
Deliberately does NOT touch checkout pricing itself — `sale_type` was
already the one thing that controls wholesale pricing (Parts A's tiers/
MOQ and the base flat `wholesale_price` both already key off it), so a
flagged customer only changes what the toggle defaults to, never the
math. Tests: `tests/Feature/WholesaleCustomerGroupsTest.php`, 7 passing
— create/toggle, feature-gating, tenant scoping, both lookup endpoints
surfacing the flag.

**Part C — shipped 2026-10-21.** `shops.wholesale_invoice_counter`
(default 1000, same pattern as `invoice_counter`/`quotation_counter`/
`po_counter`, same lock already taken at the top of
`performCheckout()`). `PosController::performCheckout()`'s invoice-
number step now branches: a wholesale sale under the fuller
`wholesale_tiers` feature gets `'WS-'.++wholesale_invoice_counter`;
everything else (a retail sale, or a wholesale sale on a shop with only
the base `wholesale_pricing` feature) keeps the exact `'INV-'` series
unchanged. Confirmed nothing else in the codebase parses an
`invoice_no`'s prefix — every other reference (reports, exports,
receipt print, the `ReceiptMemo` scan-barcode, search) treats it as an
opaque display string, and the receipt's own scan-to-reopen barcode
separately always encodes `'INV-'.sale.id` regardless of `sale_type`
(a different, already-existing internal id scheme, untouched here).
Tests: `tests/Feature/WholesaleInvoiceNumberingTest.php`, 4 passing —
WS- series under the full feature, INV- series preserved both for a
retail sale and for a wholesale sale under only the base feature, and
the two counters proven independent/non-colliding across interleaved
sales.

**Phase 30 (the full Wholesale system) is now complete** — all three
parts (A: tiered pricing + MOQ, B: wholesale customer groups, C:
separate invoice numbering) shipped and deployed. This was the last
Phase-4 item Khaled had approved.

## Phase 31 — "Online Business" module, part 1: sales channels + channel pricing

Khaled gave explicit go-ahead (2026-10-25) to start the "Online
Business" module — a large, separate initiative (multi-channel online
orders, couriers/COD, ad-spend allocation, a two-view profit engine).
Full spec re-read verbatim from the original paste (its own §0 ground
rules, §1 reuse map, §4's 8 parts). Built serially, one part per commit/
deploy, same discipline as every Phase 3/4 feature.

**Reuse-vs-build check (per §0's own instruction, before coding):**
reusing the exact `BelongsToTenant`/tenant-scoping pattern, the
`Feature`/`FeatureSeeder` opt-in mechanism, `config/staff_permissions.php`'s
existing staff-grant checklist (adding one new `online_business` entry —
*not* a new per-channel staff-assignment feature; "staff assignment via
existing permissions" in the spec means gating the whole module behind
the existing owner-grants-to-cashier checklist, same as `purchases`/
`promotions`/etc. already do), and the existing `Storage::disk('public')`
upload pattern products already use for photos (reused for a channel's
logo). Genuinely new this part: a `sales_channels` table (channel CRUD)
and an optional `channel_product_prices` override table — nothing else
in the codebase has either concept yet.

**Channels are shop-scoped** (plain `BelongsToTenant`, exactly like
every other catalog entity), not shared across a business's branches.
The spec's §1 reuse-map row flags this as a decision to "document": a
branch wanting its own channel list defines it independently, same as
every other feature-gated entity in this app without an explicit
cross-branch sync (e.g. `wholesale_pricing`, `promotions` before
`shared_promotions` existed). If Khaled later wants one shared channel
list across branches, that is an explicit, separate follow-up — not
assumed here, and not needed for Part 1's own test list.

**`sales_channels` fields** (per spec's exact Part 1 list): `name`,
`platform_type` (a short validated string — `facebook`/`website`/
`daraz`/`whatsapp`/`catalog`/`other` — drives the list's icon, not a
hardcoded business rule, easy to extend with one more validation value
later), `logo_path` (nullable), `active` (default true), `order_prefix`
(e.g. `FB1-`, unique per shop — this is what Part 2's online-order
numbering will prefix its own per-channel counter with, so reserving
both `order_prefix` and `order_counter` here even though nothing
consumes them until Part 2, since they're properties of the channel
entity being created right now), `platform_fee_percent`/
`gateway_fee_percent` (nullable decimals), and `default_courier_id`
(nullable, **no FK constraint yet** — Part 3 creates `couriers` and adds
the constraint then; exposed in the UI starting Part 3 too, since an
empty courier dropdown today would be confusing, not useful).

**`channel_product_prices`** (the spec's "optional per-channel price
override table"): `shop_id`, `channel_id`, `product_id`, `price` — one
row per product a channel's owner has explicitly overridden; a product
with no row for a channel falls straight through to that product's
normal price (`discount_price ?? price`, the same fallback checkout
already uses) — `SalesChannel::priceForProduct()` encapsulates this so
Part 2's online-order pricing (which must run through the *exact* same
price path POS checkout uses, per the spec's own ground rule) calls one
method rather than re-deriving the fallback logic.

**New opt-in feature**: `online_business` (never auto-granted). **New
staff permission**: `online_business` in `config/staff_permissions.php`
(category "বিক্রি", alongside `promotions`). Route group middleware:
`['perm:online_business', 'feature:online_business']`, mirroring the
exact `purchase_orders` pattern.

Tests: `tests/Feature/SalesChannelTest.php`, 12 passing — CRUD, feature
gating, staff-permission gating, `order_prefix` uniqueness per shop (and
proven NOT unique across shops), tenant scoping, `priceForProduct()`
fallback vs. override, set/clear via the controller endpoint, and the
prices listing page's shape.

## Phase 32 — "Online Business" module, part 2: online orders on shared stock

**The central decision this part had to make and justify** (per the
spec's own §3): *"reserve or decrement"* at order creation, and how to
keep every existing report number unchanged for a non-delivered online
order. Decided: **decrement immediately, exactly like a POS sale** —
not a soft reservation. An online order creates its `Sale`/`SaleItem`/
`SalePayment` rows and moves real stock the moment it's placed (status
`pending`), reusing `BatchStock`/`SerialStock`/variant-decrement
directly (the same primitives `PosController::performCheckout()` uses,
called the same way, under the same per-product `lockForUpdate()` —
concurrency safety is therefore inherited for free, not reinvented).
Rejected the alternative (a parallel "reserved but not yet decremented"
ledger) because it would have needed its own stock-availability
subtraction logic layered on top of every existing stock check
(POS included) *and* its own FEFO/serial allocation preview — a second,
parallel stock-accounting path the module's own ground rules explicitly
warn against. Decrementing immediately needs none of that: by the time
a report or POS checkout reads `products.stock`, it's already correct,
the same way it always has been.

**Keeping "existing report numbers unchanged" cheap, not a 20-method
retrofit.** `Reports.php` has 20+ independent static methods, each
running its own `Sale::` query — there is no shared base query to patch
once. Rather than touch every one of them (real regression risk, and
arguably Part 6's job, which builds the dedicated profit engine), this
part adds a single boolean column, `sales.counted_in_reports` (default
`true`), and folds one extra condition into `Sale`'s own existing
`'active'` global scope (the same scope that already hides a voided
sale from every normal query, bypassed today via `Sale::withVoided()`).
An online order's `Sale` is created with `counted_in_reports = false`
and flips to `true` only once the order reaches `Delivered`. Every one
of the 20+ `Reports::` methods, `Home`'s dashboard tiles, P&L, balance
sheet and partner profit-share — all of which just query `Sale::` —
therefore exclude a non-delivered online order automatically, with
**zero changes to any of them**. `Sale::withVoided()` (already used by
`SalesController::show()` for direct deep-links) is reused as-is to
let the new Online Orders pages see their own sales regardless of
status — no new scope-bypass method needed either.

**What's deferred to `Delivered` vs. recorded immediately**, decided by
what each effect actually represents:
- Recorded immediately (at `pending`): the `Sale`/`SaleItem` rows, real
  stock decrement (incl. FEFO batch pick, variant stock, a chosen
  serial), `SalePayment` rows, `cash_balance`/`bank_balance` for any
  genuinely-prepaid portion (that money really is already in the
  shop's account), and the customer's `due` for any shortfall (a real
  credit obligation from the moment it's extended) — all via the exact
  same accounting PosController's checkout already does.
- Deferred to `Delivered`: `counted_in_reports` flip, `delivered_at`,
  customer `total_spent`/`visits` (lifetime purchase stats — a
  cancelled pending order should never have nudged these even
  temporarily), loyalty points earned, and the first-sale referral
  reward (see docs/FIX_PLAN.md's referral design note) — all represent
  a *completed* purchase, not merely a placed one.
- COD specifically: `cod_amount` is recorded as its own new
  `sale_payments.method = 'cod'` tender line at creation — explicitly
  **not** bumping `cash_balance`/`bank_balance` (the money is with the
  courier, not the shop, until Part 3's COD settlement screen actually
  reconciles it — that's where it becomes real cash). `SaleReversal`
  (shared by the owner-void route and the admin hard-delete tool) gets
  one new branch so voiding a `cod` tender never wrongly debits
  `bank_balance` for money the shop never actually received.

**Cancel/return reuse an existing, already-tested mechanism, not a new
one.** Cancelling a not-yet-delivered order calls the exact same
`SaleReversal::reverse()` used by `SalesController::destroy()` (owner
void) and the admin's hard-delete tool — restores stock (batch/serial/
variant), reverses any genuinely-received payment, reverses customer
due — then marks the `Sale` `voided_at` (excluded from reports via the
*existing* mechanism, no new flag needed for the cancelled case) and
the order `cancelled`. A full return after `Delivered` reuses the same
reversal for now; **partial** return with per-line Good/Damaged/Lost
condition is explicitly Part 4's job (its own design note) — this part
only wires `Returned`/`Failed`/`Returning` as valid lifecycle statuses
with audit logging, not yet their own compensating-entry nuance beyond
a full reversal.

**No separate audit-log table.** "Every transition logged (who, when,
old/new)" reuses the *existing* polymorphic `ActivityLog`/`Activity::log()`
helper (already shown on the Activity page) instead of a bespoke
`online_order_status_logs` table — one more table this part doesn't
need to invent.

**Pricing stays a historical snapshot, never re-derived.** Per the
spec's own test list ("later price/cost changes never alter past
profit"), `SaleItem.price`/`.cost` are fixed at order-creation time via
`SalesChannel::priceForProduct()` (Part 1) — there is no re-pricing
step at any later lifecycle stage.

**Numbering**: a new `shops.online_invoice_counter` (default 1000, same
pattern as `invoice_counter`/`wholesale_invoice_counter`/`po_counter`)
gives every online sale its own `'OL-'` invoice series, never touching
the retail `INV-`/wholesale `WS-` series. Separately, `online_orders.
order_number` (the channel's own `order_prefix` + its own
`order_counter`, from Part 1) is the customer/channel-facing order
reference — intentionally a different number from the accounting
invoice, matching how a real online shop already has both.

**New `sale_type` value**: `'online'` (enum extended, same migration
pattern as `wholesale`/`exchange`/`service` before it) — so the
existing `Reports::salesByType()` breakdown gains an "online" bucket
for free, once an order is delivered/visible, with zero code change
there either.

**Scope deliberately left out of this part** (each has its own later
home): CSV import's *multi-item-per-order* rows (a CSV row here is
exactly one product/qty — a multi-item order still goes through the
manual form; most Facebook/Daraz order exports are single-SKU per row
anyway); partial returns with Good/Damaged/Lost condition (Part 4);
courier/COD settlement UI (Part 3, `courier_id`/`tracking_id` are
reserved nullable columns here, same as Part 1's `default_courier_id`);
the channel-wise profit engine's own dedicated reports (Part 6). The
"one-click from a digital-catalog order" sub-bullet needs no new code
— no stored "catalog order" record exists today (the catalog is a
public page linking out to WhatsApp), so converting one is already
just: create a normal online order under the Catalog channel.

**Also built this part** (beyond the core lifecycle): a repeat-customer/
fake-order risk indicator (`OnlineOrderController::customerRisk()`, its
own small endpoint rather than extending the shared `customers.lookup`
POS already polls on every keystroke — a warning only, never a hard
block, shown on the Create page as the phone is typed); CSV import as a
genuine two-step preview-then-commit flow (one product/qty per row,
matches by exact barcode-then-name, flags a duplicate
`external_reference` per channel, the commit step calls the exact same
`createOrder()` the manual form uses — extracted once `store()` and the
CSV importer both needed it, so the two can't drift apart); and the
"invoice via existing print styling" requirement turned out to need no
new template at all — the online order's own `Sale` already has a
perfectly good print/WhatsApp view at the existing `/app/sales/{id}`
page (reachable even pre-delivery via the same `Sale::withVoided()`
SalesController::show() already uses for a direct deep-link), so Order/
Show.vue just links to it. A dedicated compact packing-slip/courier-
sticker format is deferred to Part 3, since its layout likely needs
courier-specific fields (tracking barcode) that don't exist yet.

Tests: `tests/Feature/OnlineOrderTest.php`, 18 passing — stock
decrements immediately and restores exactly on cancel/return; a pending
order is invisible to every report while COD never touches cash/bank
balance; delivering flips visibility and credits loyalty/lifetime
stats exactly once; prepaid payments bump balance immediately; the
full lifecycle walk and its guard against skipping a stage; variant
stock; insufficient-stock rejection leaves nothing created; feature
gating; the `salesByType` breakdown gaining an 'online' bucket with
zero changes to `Reports.php`; the risk indicator; CSV preview
(matching, error-flagging, duplicate detection) and import.

## Fix — Online Business nav missing from the mobile "More" page

Reported live by Khaled right after deploying Part 1+2 and granting
the feature to a demo shop: "Online orders"/"Online channels" showed
up correctly in the desktop sidebar (`AppLayout.vue`'s nav array) but
were completely absent from the mobile bottom-tab "More" page.
Root cause: `More.vue` is a separate page with its **own** hardcoded
card list per section — it does not read `AppLayout.vue`'s `sections`
array at all, so adding a nav entry there was never enough on its own.
Fixed by adding the same two links (gated the same way,
`hasPerm('online_business') && features.includes('online_business')`)
to `More.vue`'s "Sales" section, right after Quotations.

Parts 3-8 — not yet started.

## Definition-of-done checklist (updated after every phase)

- [x] Per-business-type + mixed-shop smoke tests all passing (`shop:smoke-test --all`, Phase 0.6) — re-verified 2026-10-20, all 8 types OK
- [x] `shops:repair-defaults --dry-run` run and reported — "সব শপের already কমপক্ষে একটা category এবং একটা unit আছে — ঠিক করার কিছু নেই।"
- [x] No new N+1 queries introduced by any phase — checked per-phase during review (e.g. `Shop::featureKeys()` property-access caching from the scale audit already guards every new `hasFeature()` call this session added)
- [x] Migrations verified — every one of the 942 suite tests runs `RefreshDatabase` against a from-scratch sqlite migration on every single run; a real `migrate:fresh --seed` against the local MySQL dev DB was deliberately NOT run here since that would wipe real local dev data without being asked
- [x] Money/stock reconciliation commands built and dry-run (`due:reconcile`) — re-verified 2026-10-20, "কোনো সমস্যা পাওয়া যায়নি"
- [x] `docs/ARCHITECTURE_NOTES.md`, `docs/FIX_PLAN.md`, `docs/OFFLINE_TEST_CHECKLIST.md`, CHANGELOG all present
- [x] Final comprehensive Bengali report for Khaled covering what changed, deploy steps, remaining risks, open questions — sent after Phase 3 feature 10 shipped

### Genuinely still open
- **Phase 4** (enterprise gap-filling, see `docs/ENTERPRISE_GAP_REPORT.md`) — approved and in progress. Done: item #1 (shared catalog sync + branch transfer accept/reject), item #2 (PO/GRN), item #3 (cross-branch promotion pooling — branch price override turned out already covered). Still to come: the full Wholesale system (tiered pricing/MOQ/customer groups/invoices — approved, not yet started). Separately noted-but-deferred: a scheduled/date-windowed markdown engine (item #3's other sub-gap).
- The Android APK rebuild reminder (new theme colors) — not a code task, tracked against the separate TWA plan below.
- A separate TWA-replaces-Capacitor plan (Part 1 of 2, a different planning session) is sitting unstarted — not touched this session since Khaled's attention has been on Phase 3/4.
