# Article-Series System — Baby Park + Liberty (shoe shop)

**Status: PLANNING ONLY. Nothing in this document has been built yet.**
Per the ticket's own instruction, this is written before any code, and the
items marked **জিজ্ঞেস করো** below are asked directly rather than guessed —
see the questions sent alongside this document.

## Context recap

Continuation of the earlier Baby Park setup (`docs/BABY_PARK_PLAN.md`,
`app/Console/Commands/SetupBabyPark.php`): one owner, two shops sharing one
stock pool via `master_product_id`/`shared_stock_pool`, separate
sales/profit/cash/due per shop. This phase adds a shoe-specific "article
series" workflow on top of the existing `Product`/`ProductVariant` model —
not a parallel product system.

**Confirmed via the existing setup command**: the two shops are currently
both named "Baby Park" (main shop + its branch, same name). The ticket asks
for the shop names to be "Baby Park" and "Liberty" — read as: **the branch
gets renamed to "Liberty"**, main shop stays "Baby Park" (it's the one tied
to the owner's phone/login). This needs a one-line `Shop::update(['name' =>
'Liberty'])` on the existing branch row — flagged here so it isn't missed,
not treated as a judgment call since it's the only reading that fits "two
shops, two different names" with an already-named main shop.

## 1. Manufacture & Purchase source — reuse `Supplier`, don't duplicate

Read `app/Models/Supplier.php`: already a full tenant model with
`name`/`phone`/`address`/`payable`, a `purchases()` relation, and
`SupplierPayment`/`SupplierReturn` tracking. This is exactly what "যেখান
থেকে পণ্য কেনা হয়" means — a manufacturer **is** a supplier in this app's
existing vocabulary. **Decision: reuse `Supplier`, add a nullable
`short_code` column** (shop-scoped unique, e.g. `SMS`), not a second
parallel table. `Product` already has no `supplier_id` column today — this
phase adds one (nullable, additive) so an article records which
manufacturer it was bought from, filterable in Stock/Reports.

## 2. Category code + race-safe serial counter

`ProductCategory` has no code column today. Adding `code` (nullable,
shop-scoped, e.g. `L` for Ladies) is straightforward — but the **serial
counter is NOT straightforward**, because of something confirmed by reading
`CatalogSync::syncCategoriesToBranch()` (`app/Support/CatalogSync.php:59-71`):
**each shop (main + branch) has its own separate `ProductCategory` row**,
matched by name during sync — there is no `master_category_id` link the way
`Product` has `master_product_id`. A counter column directly on
`ProductCategory` would NOT be shared between Baby Park and Liberty (two
separate rows), contradicting the ticket's explicit "দুই শপের জন্য একটাই"
requirement.

**Decision**: a small new table `article_series_counters` (`main_shop_id`,
`category_code`, `next_serial`, unique on the pair) — always keyed by the
**main shop's** id regardless of which of the two shops is creating the
article (branch resolves its own `parent_shop_id` to find the right row).
Incrementing uses `lockForUpdate()` + an atomic update, same pattern as
every other race-safe counter in this app (e.g. `shops.invoice_counter`).
This is a new table, not a Supplier-style column-on-existing-model reuse —
justified specifically because `ProductCategory` rows aren't cross-branch
-linked today and adding that linkage is out of scope for this phase.
`CatalogSync`'s category sync gets one small addition: copy `code` when
creating the branch's copy, so both shops' "Ladies" category shows the same
code without the owner setting it twice.

## 3. Barcode template & cost code

Shop-setting (new `shops.article_barcode_template` string, default
`{MFR}-{CAT}-{SERIAL}-{COSTCODE}`) and a `shops.cost_code_digit_map` JSON
column (10 entries, one per digit 0-9, editable in settings). Confirmed
mappings from the ticket's own decoded examples: `N=1, S=3, I=4, J=6, R=5,
X=0`. **Still open — see questions sent alongside this plan**: the letters
for 2/7/8/9, and what the trailing "Z" means (some codes have it, some
don't). Until answered, Z is carried through unchanged as an "optional
untouched suffix" exactly as the ticket allows, and digits 2/7/8/9 are left
unmapped in the default config (shown as `?` if a cost value needs one,
flagged in import/UI rather than silently guessed) until Khaled fills them
in via settings.

`Product.barcode` continues to hold the **article-level** barcode
(`SMS-L-10001-NSXXZ`) exactly as it does today for a non-variant product.
Variant barcodes are new: `ProductVariant.barcode` gets set to `{article
barcode}-{color code}-{size}` at creation time (color code = a new
`shops.color_code_map` JSON, or the color name itself uppercased if no code
is configured — never blocks saving). This is not just cosmetic: per
`ProductVariant::stockPoolMemberIds()` (`app/Models/ProductVariant.php:44-56`),
cross-branch variant matching for the shared stock pool already prefers an
**exact barcode match** over size+color matching — so identical,
deterministic variant barcodes across Baby Park and Liberty make the
existing pooling logic MORE reliable, not just more scannable. This is a
real technical reason behind the proposed format, not only a UX preference
— still confirming with Khaled per the ticket's explicit ask, but flagging
that there's a concrete "yes" case for it.

## 4. Baby-shoe size-group pricing

New `product_category_settings` row (or a column directly on
`ProductCategory` — leaning toward a column, `size_group_pricing_enabled`
boolean, default off, since this is a single per-category toggle, not a
growing settings bag) + a new small table `product_size_group_prices`
(`product_id`, `label`, `min_size`, `max_size`, `price`, `cost` nullable).
Resolution order at checkout/display time: variant's own `price` override
(existing column, unchanged) → matching size-group price (new) → article's
base `price` (existing, unchanged). Zero behavior change for Ladies/Gents
or any shop with the category setting off.

## 5. UI — article page / POS color-then-size picker

Reuses `Pos/Index.vue`'s existing variant-picker pattern (color chips →
size table) rather than inventing a new component — that picker already
exists for the clothing vertical and this is the same shape (pick
color/attribute, then size+qty). The new piece is the color-chip row with
a per-color stock total and optional photo, feeding into the existing
variant `<button>` grid. Stock page gets a new "color × size matrix" view
for an article, built from the same `variants` relation already eager-loaded
everywhere else — no new data-fetching path.

## 6. Excel import

New command `products:import-articles {file} {--dry-run}`, modeled on
`app/Http/Controllers/App/ProductImportController.php`'s existing safety
patterns (barcode-collision-before-create ordering, no silent stock
miscounts) but reading an .xlsx via `maatwebsite/excel` (already a
composer dependency — confirmed in `composer.json`, no new package needed)
instead of CSV, with the
size-in-cell-text parsing rule the ticket specifies (`36--(1)---37--(1)`
etc.), the `(sold)` → 0 rule, the `COFFE`→`COFFEE` typo note, the grid-sum-
vs-stock-column mismatch report, and the `L-10001`/`L10003` barcode-driven
name normalization. **Per the ticket's own explicit instruction: this
command will be run with `--dry-run` only, and its report shown to Khaled,
before any real import runs** — not optional, not inferred permission.

**Blocking**: `docs/import/BabyPark_Product_Entry_1.xlsx` and
`docs/import/logo.jpg` don't exist in this repo yet (checked — no
`docs/import/` directory at all). Can't inspect the real column/row shapes
or verify the cost-code examples against real data until these are
actually placed in the repo (or their content is pasted/shared another
way) — see the question sent alongside this plan.

## 7. One-screen article entry

New page `App/Articles/Create.vue` (or extends `Stock/Index.vue`'s existing
sheet — leaning toward a dedicated page given the amount of new UI: color×
size grid, live barcode preview, manufacturer/category autosuggest — a
sheet would get cramped). Backend: one new `ArticleController::store()`
wrapping `Product::create()` + N `ProductVariant::create()` + optional
`Purchase::create()` in a single DB transaction, idempotency via a
client-generated UUID on the submit payload (same `client_uuid` pattern the
offline sale queue already uses) so a double-tap never creates two
articles. Draft autosave to `localStorage` (not IndexedDB — this is a
single in-progress form, not data that needs querying), restored on page
reload. "Copy this article" / "next article remembers manufacturer+
category+size range" are plain client-side state carried into the next
form instance, nothing server-side.

Price/name/category edits propagating live to POS/Stock/reports without a
reload: existing Inertia partial-reload (`router.reload({ only: [...] })`)
and the polling patterns (`usePollingReload`) already used elsewhere cover
this — no new live-update mechanism needed.

## 8. Offline behavior (explicit decision, per the ticket's own ask)

- **New article creation requires being online** (serial-counter
  uniqueness can't be guaranteed offline across two shops/devices). A
  started-but-unsent article form is saved as a draft (see §7) and
  resubmitted once online — never silently queued as a "real" article the
  way offline sales are.
- **Selling, scanning, and viewing stock stay fully offline**, same as
  today — the color/size/barcode fields just ride along in the existing
  offline product cache (`offlineDb.js`'s `products` store already caches
  whatever fields the server sends per product/variant, no schema change
  needed there beyond what's already generic).

## Open questions (sent via AskUserQuestion alongside this document)

1. Cost-code digit map for 2, 7, 8, 9.
2. What the trailing "Z" means on some cost codes.
3. Confirm (or correct) the variant barcode format
   `{article barcode}-{color code}-{size}`.
4. How to actually get `BabyPark_Product_Entry_1.xlsx` and `logo.jpg` into
   this repo/session for inspection.
5. Confirm the branch-rename-to-"Liberty" reading above is correct.

## Explicitly NOT started

No migration, model, controller, or Vue file for this feature has been
written yet. This document and the five questions above are the only
output of this pass.
