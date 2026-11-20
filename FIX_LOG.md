# Fix Log

Part of the full technical audit (section 16, deliverable #11). Every code change made as a direct result of this audit, in the format section 15 asks for: root cause → fix → test → verification.

---

## FIX-001 — Warehouse/head-office checkout guard moved to the shared choke point

- **Bug**: BUG-001 (`BUG_REPORT.md`) / P2 finding from `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md`.
- **Root cause**: the `is_warehouse`/`is_head_office` checkout-block guard lived only in `PosController::checkout()`; `performCheckout()` (the method every real caller — the HTTP route, the payment-gateway webhook, offline-sync replay — actually funnels through) had no guard of its own.
- **Fix**: `app/Http/Controllers/App/PosController.php` — moved the `abort_if($shop?->is_warehouse || $shop?->is_head_office, 422, ...)` check into `performCheckout()`, immediately after the shop row is locked. Removed the now-redundant duplicate from `checkout()`.
- **Test added**: `tests/Feature/CheckoutTransactionTest.php::test_performcheckout_itself_blocks_a_warehouse_regardless_of_caller` — calls the static method directly, bypassing the HTTP route entirely, so it can only pass if the guard is enforced at the shared method.
- **Verification**: full suite green at time of fix (1766/1766 PHP, 343/343 JS). Committed `58ec3a30`, pushed, deployed, production `HEAD` confirmed matching via direct SSH check (this session, prior to this audit's continuation).

---

## FIX-002 — Purchase of a variant now respects weighted_average_costing

- **Bug**: BUG-002 (`BUG_REPORT.md`) / P1 finding from `INVENTORY_STOCK_AUDIT.md` — confirmed live financial-reporting impact on SEAMAN.
- **Root cause**: `PurchaseController.php`'s variant-purchase branch (inside `applyEffects()`) always plain-overwrote `ProductVariant.cost`, never checking `Shop::usesWeightedAverageCosting()` — unlike `ProductVariantController::stockIn()`, which correctly blends. The branch's own comment incorrectly claimed the two matched "exactly."
- **Fix**: `app/Http/Controllers/App/PurchaseController.php` — rewrote the variant-purchase cost resolution to mirror `ProductVariantController::stockIn()`'s logic exactly:
  - If the shop has `source_lines`: resolve the new cost via `ProductSource::upsertLine()`'s own blended per-lot cost (as before), and additionally sync the mirror product's `cost` to the line's cost (this sync was missing entirely before — a second, smaller gap found while fixing the first).
  - Otherwise: blend `(oldStock × oldCost + newQty × newCost) / (oldStock + newQty)` when `usesWeightedAverageCosting()` is true and there's existing stock to blend against; plain-overwrite otherwise (unchanged default behavior for every shop without the feature — e.g. Baby Park).
- **Test added**: `tests/Feature/BrandTest.php::test_a_purchase_of_a_variant_respects_weighted_average_costing_the_same_way_stockin_does` — reproduces the audit's exact numbers (1 unit @1600, +5 @1750 → 1725 with the feature on) via the real `/app/purchases` route, plus a second case on a plain shop confirming the pre-existing plain-overwrite behavior (550) is completely unchanged.
- **Verification**:
  - `BrandTest.php` + `ProductSourceStockInTest.php` + `PurchaseOrderTest.php` (the three files touching this code path or its siblings): 23/23 passed.
  - Full suite: **1774/1774 PHP passed** (was 1773 before this fix's own new test; +1 net), **343/343 JS passed** — zero regressions.
- **Status**: fixed and tested locally this session; commit/deploy happens together with this audit's documentation in the same batch (see `git log` for the exact commit hash — this file is written just before that commit).

---

## FIX-003 — Closed a 6-day gap in nightly database backups

- **Bug**: BUG-003 (`BUG_REPORT.md`) — operational, not a code defect.
- **Root cause**: cron likely wasn't fully active on the newly-migrated Cloud Professional server for several days after the 2026-11-14 cutover.
- **Fix**: no code change — ran `php artisan zaylotix:backup` manually on production during this audit.
- **Verification**: `zaylotix-pos-2026-11-20_114234.sql` confirmed present on disk (`storage/app/private/backups/`), 5.26MB.
- **Status**: immediate gap closed. Needs a human check tomorrow (2026-11-21) that the scheduled run resumes on its own — this audit cannot verify a future cron firing.

---

## Explicitly NOT fixed in this pass (by design — see `BUG_REPORT.md` for each)

- BUG-004 (dependency CVEs) — `composer update` across 11 packages needs its own dedicated regression pass, not folded into an audit silently.
- BUG-005, BUG-006, BUG-008, BUG-009, BUG-010, BUG-011, BUG-012 — all P3/NOT TESTED/dormant, none urgent, none touched per the master prompt's own rule against unnecessary changes to working code ("Do not make unnecessary rewrites or change working business logic without justification").
