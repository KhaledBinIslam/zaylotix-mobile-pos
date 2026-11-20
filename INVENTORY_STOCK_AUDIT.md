# Inventory & Stock Audit

Section 3 of the master audit prompt ("Critical Inventory & Stock Audit").
Evidence standard: **VERIFIED PASS** (real test(s) + code trace confirm it),
**VERIFIED FAIL** (reproduced with evidence), **NOT TESTED** (code exists,
no automated coverage found), **POTENTIAL RISK** (plausible but not
reproduced). All checks below are code-trace + automated-test evidence —
nothing was run against production or live customer data.

## A. Stock In (purchases)

| Scenario | Result | Evidence |
|---|---|---|
| Repeated purchases accumulate stock | VERIFIED PASS | `SupplierTest::test_a_purchase_can_add_stock_in_the_same_request` — 50+30=80 |
| Base-product cost blends as weighted average | VERIFIED PASS | Same test: `(50*10+30*15)/80 = 11.875` matches `App\Support\StockIn::apply()` exactly |
| Purchase with different variations | VERIFIED PASS | `PurchaseController.php:185-229`, variant branch, locked |
| Partial payment + due on a purchase | VERIFIED PASS | `SupplierTest` credit-purchase cases; `Supplier.payable` increments correctly |
| Purchase cancellation / mark-received lifecycle | VERIFIED PASS | `PurchaseController::markReceived()` pending→received flow has dedicated tests (see FEATURE_AUDIT.md for the full matrix; not duplicated here) |
| Return purchased stock to supplier | VERIFIED PASS | `SupplierReturnController` — variant products are deliberately blocked (by design, redirects to Stock page), documented in `docs/FIX_PLAN.md` 4.4/4.5, unchanged |

All locking/transaction discipline for purchases: `DB::transaction` + `Product`/`Purchase`/`ProductVariant` all fetched via `lockForUpdate()` before mutation (`PurchaseController.php:50,132,138,186,201,257`) — **VERIFIED PASS**, no oversell/undercount race window found.

## B. Stock Out (sales)

Qty, variations, discount (line + overall), VAT, partial payment, due, cancellation/void, and return/restock are all covered by `CheckoutTransactionTest`, `ReturnExchangeTest` (36 tests), `DiscountPriceCheckoutTest`, `WholesaleTiersTest` — **VERIFIED PASS** across the board; re-ran all of them fresh, 87/87 passed. Locking discipline in `PosController::performCheckout()` (`lockForUpdate()` on Shop, Product, ProductVariant, Customer; whole method in `DB::transaction`) matches the purchase side.

One deliberate, owner-confirmed exception: Baby Park's `allow_oversell` per-line flag lets a sale proceed past a stock shortfall (`PosController.php` ~line 952) — this is a named, confirmed business decision, not an oversight; `products.stock` is a signed `integer` (not `unsigned`, confirmed in `2024_01_01_000007_create_products_table.php`), so going negative is safe at the DB level, no truncation/overflow risk. **VERIFIED PASS, by design.**

## C. Exchange

`ReturnController::createExchangeSale()` (`ReturnController.php:782-971`) is exceptionally well covered — 36 tests in `ReturnExchangeTest.php` including both exchange directions (pricier/cheaper/equal), the documented `trade_credit` SalePayment fix (`docs/FIX_PLAN.md` Phase 3.1 — verified still correctly in place: `ReturnController.php:947-954`), variant restocking, discount on new items (clamped to the line's own total), void-after-exchange blocking, void-after-partial-refund, loyalty-point proportional deduction, and cross-report profit/revenue consistency. **VERIFIED PASS**, re-ran fresh, all green.

**Gap found (NOT TESTED, P3):** every exchange test in the suite uses exactly one `new_items` line. The controller's own loop (`ReturnController.php:794-837`) clearly supports 2+ new items in one exchange (multi-product exchange, section 3.C's own requirement), but no test exercises that specific case. Low risk — it's a straight extension of the single-item path with no special-cased math — but it is a genuine coverage gap per the master prompt's explicit ask. Recommend one new test: exchange 1 item for 2 different new items in a single request, confirm `newTotal`/`newCost`/stock move correctly for both lines.

## D. Stock Consistency (Opening + In − Out = Current)

No duplicate/missing stock movement found in any audited path. Every money/stock-moving controller (`PosController`, `PurchaseController`, `ReturnController`, `StockTransferController`, `RentalController`) wraps its write in `DB::transaction` with `lockForUpdate()` on every row it mutates — confirmed by direct code read, not assumption. **VERIFIED PASS.**

## E. Stock Valuation — the actual costing policy (discovered, not assumed)

This codebase does **not** use one single costing method — it's genuinely three different policies depending on path, and one of them is inconsistently applied:

1. **Plain (non-variant) product, any stock-in path** — unconditional **weighted average**, via the shared `App\Support\StockIn::apply()` helper: `newCost = (oldStock*oldCost + qty*newCost) / (oldStock+qty)`. Used by both `ProductController::stockIn()` and `PurchaseController`'s plain-product branch, so they can never disagree. **VERIFIED PASS.**
2. **Variant/ingredient cost, in general** — governed by a per-shop opt-in feature flag, `weighted_average_costing` (`Shop::usesWeightedAverageCosting()`, `Shop.php:394-396`: `hasFeature('weighted_average_costing') && !hasFeature('source_lines')`). Off by default for every shop (Baby Park's world: plain overwrite, confirmed intentional per `BrandTest.php:104-113`); **SEAMAN has this feature explicitly granted** (`SetupSeamanShop.php:65`, "Phase 4 — variant stock-in averages cost instead of overwriting").
3. **source_lines shops** — per-lot specific identification (`ProductSource`), not an average at all; each lot keeps its own real cost. Correctly takes priority over #2 when both are on.

**Confirmed bug (VERIFIED FAIL, P1):** for a shop with `weighted_average_costing` on, the **manual Stock-page stock-in** (`ProductVariantController.php:336-343`) correctly blends the new cost into the average. The **Purchase-screen stock-in for the exact same variant** (`PurchaseController.php:192-228`) does **not** — it always does a plain overwrite, with no check of the flag at all, despite its own comment (`PurchaseController.php:196-198`) explicitly claiming it "match[es] ProductVariantController::stockIn exactly" — that claim is stale and no longer true.

Reproduced live (scratch test, not left in the suite): same shop, same feature, same numbers as `BrandTest`'s passing case (1 unit @1600, receive 5 more @1750 → correct weighted average is 1725):
- Via `POST /app/productVariants/{id}/stockIn` → variant cost = **1725** (correct).
- Via `POST /app/purchases` with the same `product_variant_id`/qty/cost → variant cost = **1750** (wrong — the new cost, not the average).

**Impact**: this is the shop's real, recorded supplier payable path (not just a quick manual top-up) — SEAMAN is a real paying client with variants and this feature on. Any variant restocked via the Purchase screen (the normal way to record a supplier bill with due/payment, not just a quick stock bump) silently gets the wrong recorded cost, which then feeds every profit/margin figure (Sales profit, product-wise reports, Accounts stock valuation) for that variant until it's restocked again. **Recommend**: route `PurchaseController`'s variant branch through the same `usesWeightedAverageCosting()` check `ProductVariantController` already uses (or better, factor both into one shared helper, mirroring `StockIn::apply()`'s role for plain products) — flagging for Khaled's fix decision, not fixed in this audit pass per the audit's own ground rules.

**Stock valuation display consistency**: `cost * stock` is used consistently as "inventory asset value" across `AccountsController::stockValue()/stockValueDetail()`, `PartnerController`, `SupplierController`, and the branch-breakdown export — **VERIFIED PASS**.

**Minor naming inconsistency (POTENTIAL RISK, P3)**: `DeadStockController.php:62` computes its own `'stock_value'` as `stock * price` (retail/sell-through value), not `stock * cost` like every other place that uses that exact field name. Likely intentional for that page's purpose (showing recoverable sale value for markdown decisions), but the identical key name next to a conceptually different figure elsewhere in the app is worth a one-line doc comment so a future reader doesn't assume they're the same number.

## Bugs found this pass

| ID | Severity | Summary |
|---|---|---|
| INV-1 | **P1** | Purchase-screen variant stock-in ignores `weighted_average_costing`, always overwrites cost instead of blending — real financial-accuracy gap for SEAMAN (and any future variant shop that enables this feature). Reproduced live. Not fixed (audit-only pass). |
| INV-2 | P3 | No automated test for a multi-line (2+ products) exchange in one request — code supports it, untested. |
| INV-3 | P3 | `DeadStockController`'s `stock_value` field means `stock*price`, not `stock*cost` like identically-named fields elsewhere — naming/doc gap only, not a math error. |

No P0 (data/financial corruption causing silent loss) or P2 found in this section beyond INV-1/2/3 above.
