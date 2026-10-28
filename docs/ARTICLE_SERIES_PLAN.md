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

**Resolved by Khaled**: (1) the Small/Medium/Large size boundaries must be
owner-configurable per shop ("owner tar shop theke customised kore set
korte pare"), not fixed; (2) this applies **only** to বাচ্চাদের জুতা
(Kids' Shoes) — confirmed as its own category in `config/business_types.php`
line 143, already seeded for Baby Park; (3) the entry screen shows **3
price boxes** (Small/Medium/Large), one per size-group, instead of one.

**Built this pass (foundation only, matching last pass's §1/§2 approach)**:
`shops.kids_shoe_size_groups` (nullable JSON, `Shop::kidsShoeSizeGroups()`
merges onto `KIDS_SHOE_SIZE_GROUPS_DEFAULT` — same null-means-default
convention as `costCodeDigitMap()`) plus a new pure-logic
`App\Support\SizeGroupPricing` (`groupForSize()`, `priceForSize()`) —
unit-tested the same way as `ArticleBarcode`.

**No new table or column needed for the price itself** — confirmed by
reading `ProductVariant::effectivePrice()`
(`app/Models/ProductVariant.php:29-32`): it already resolves
`$this->price ?? $this->product->price`, i.e. a variant's own `price`
column already overrides the article's base price today, for every shop.
So resolving a kids'-shoe variant's price is just: look up its size's
group via `SizeGroupPricing::groupForSize()`, take that group's entered
price, and set it as the variant's own `price` at creation time —
`effectivePrice()` needs no change at all.

**Not built yet (needs §7's entry screen to actually enter the 3 prices,
and/or an import-sheet format once a kids' shoe row exists in a real
file — the current bundled file has none)**: the 3-price-box UI itself,
and wiring `SizeGroupPricing` into the import command. Deferred to the
same follow-up pass as §7, flagged here so it isn't forgotten.

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

## Questions — resolved

1. **Branch rename**: confirmed by Khaled — branch becomes "Liberty", main
   shop stays "Baby Park".
2. **Files**: Khaled placed the real files at `D:\baby park and liberty\`;
   copied into `docs/import/BabyPark_Product_Entry_1.xlsx` and
   `docs/import/logo.jpg` (both verified readable — logo is a combined
   "Baby Park and Liberty" branding image, used for both shops unless
   Khaled wants two separate ones later).
3. **Variant barcode format**: Khaled's answer restated/confirmed the
   article-barcode component breakdown (MFR/CAT/SERIAL/COSTCODE) without
   objecting to appending `-{colorcode}-{size}` for the per-variant
   sticker — proceeding with the recommended format given the real
   technical reason (stock-pool cross-branch matching) already in §3.
4. **The trailing "Z" — resolved by reading the real file, not asked**:
   `docs/import/BabyPark_Product_Entry_1.xlsx`'s own data proves Z carries
   no cost information at all — cost `1350` appears as BOTH `NSRXZ` (row 3)
   and `NSRX` (row 11); cost `1450` appears as BOTH `NIRXZ` (row 5) and
   `NIRX` (rows 7, 8). Decision: strip an optional trailing `Z` before
   decoding the 4-letter cost code, keep it as-is in the stored barcode
   string (round-trips correctly), never assign it meaning. Matches the
   ticket's own stated fallback exactly, now with real evidence instead of
   just a default assumption.

## Question still open (non-blocking)

**Cost-code letters for digits 2, 7, 8, 9** — the real import file only
ever uses costs 1300/1350/1450/1500/1650 (digits 1,3,4,5,6,0 — all already
known: N/S/I/R/J/X), so this import doesn't need the other four letters at
all. **Decision: proceed without blocking** — `shops.cost_code_digit_map`
ships with the 6 known letters pre-filled and 2/7/8/9 left unset; decoding
a cost that needs an unset digit shows a clear "can't fully decode yet"
state (never a wrong guess) until Khaled fills the rest in via settings
whenever a shoe actually costs that much.

## Real file inspection — confirms the plan, no surprises

Read with PhpSpreadsheet directly (`maatwebsite/excel`'s underlying
library, already a dependency): 3 sheets (`Guide`, `Products`, `Lists`).
`Products` has only **10 real data rows** (rows 2-11) despite Excel
reporting `highestRow=1997` (routine conditional-formatting bleed — the
import command must stop at the first row with no manufacturer/name, not
trust `highestRow`). Confirmed: exact 20-column header row as the ticket
describes; `COFFE` (not `COFFEE`) really is in the file; `name_en` really
mixes `L-10001`/`L10003` styles; grid-cell sums really do disagree with
the flat `stock` column (e.g. row 2: SILVER 1+1+2+1+0=5, KHAKI
1+1+2+1+1=6, total 11 vs. `stock` column's 12); `(1 & SOLD)`/`(1& SOLD)`
both really appear (rows 8, 10); row 2's `cost` is genuinely blank (not a
mismatch — a gap, decode `NSXXZ` → 1300 to fill it, flagged as
"filled from barcode" in the dry-run report, not silently assumed). No
grey "Kids Sneaker" example rows present in this file (the exclusion rule
is still built, defensively, for a future re-upload that might have them).
`Lists` sheet's category names are the Bengali footwear defaults already
in `config/business_types.php` (ছেলেদের জুতা/মেয়েদের জুতা/...) — confirms
mapping the Excel's English `LADIES` to the existing `মেয়েদের জুতা`
("Women's Shoes") category, not creating a duplicate.

## §4 SkyBuy-style color picker — built (POS + Stock)

Per Khaled's reference screenshot and confirmation ("দুটোই" — both screens).
**Correction found while scoping this**: Baby Park's `footwear` business
type was never actually wired into `Clothing/Pos.vue` — `PosController::index()`'s
component choice only checks `slug === 'clothing'`, so a shoe shop has
always rendered the generic `Pos/Index.vue`. The redesign therefore landed
there, not on the Clothing POS page.

- `product_variants.photo_path` (nullable) — one optional photo per color,
  applied to every variant sharing that color (never just one size), via
  `ArticleController::store()` (new article) and a new
  `ProductVariantController::updateColorPhoto()` (existing article, from Stock).
- `resources/js/support/productDisplay.js`'s new `colorGroups()` — pure,
  unit-tested grouping shared by both screens, so POS and Stock never drift.
- **POS** (`Pos/Index.vue`): the old flat pill list of every size/color combo
  is now a color chip row (photo or plain swatch) → the selected color's
  sizes as a price/qty-stepper/Add table, matching the reference layout.
  `addToCart()` gained an optional `qty` parameter (default 1, so every
  existing caller is byte-identical) instead of looping single adds.
- **Stock**: the existing variant-management list (stock-in/remove/barcode,
  all unchanged) is now grouped under each color's photo + an upload/change
  control — an editing tool, not a buying flow, so it keeps its own
  controls rather than adopting POS's qty-stepper+Add shape verbatim.
- A product with no photos uploaded for any color looks and behaves exactly
  as before (plain swatch, same picker). Zero effect on checkout/stock math.

## §7 one-screen article entry — built

Confirmed by Khaled ("এখনই বানাও"): `App\Http\Controllers\App\ArticleController`
(gated by the new `article_series` feature flag) + `App/Articles/Create.vue`
+ `App/Articles/SizeGroupSettings.vue`. Reuses `ArticleSerial`/`ArticleBarcode`/
`SizeGroupPricing` exactly as the import command does, but reserves a REAL
serial (`ArticleSerial::next()`, not `bumpFloor()`) since a manually-entered
article has no pre-existing barcode to read a serial back out of. Manufacturer
and category both reuse-or-create (mirrors `ProductController::store()`'s own
pattern). Idempotent resubmit via a client-generated UUID, cached 24h (no
schema change — `Cache::put`, same spirit as the plan's original
"client_uuid" idea, simpler than a dedicated column). Draft autosave to
`localStorage`; "once entry, everywhere" — a successful submit keeps
manufacturer/category/colors/sizes in the form and only clears
cost/price/stock, ready for the next article in the same run. Entry point:
a feature-gated link on Stock/Index.vue, visible only once `article_series`
is granted.

Not yet built (would need a real kids'-shoe row in an Excel file to design
against): wiring `SizeGroupPricing` into `products:import-articles` itself.

## Build order for this pass (not everything in the ticket at once)

Given the size of this ticket, building it in one uninterrupted pass risks
exactly the kind of rushed mistake the house "audit-then-fix" discipline
exists to prevent. This pass covers:
1. Data model: `suppliers.short_code`, `product_categories.code`, the new
   `article_series_counters` table, `shops.article_barcode_template`/
   `cost_code_digit_map`/`color_code_map`, `products.supplier_id`,
   variant barcode generation.
2. `products:import-articles {file} {--dry-run}` — **run with `--dry-run`
   only**, report shown to Khaled, explicit "ঠিক আছে" required before any
   real import runs (per the ticket's own hard rule).

Deliberately NOT in this pass (separate follow-up once the above is
confirmed working against real data): §3 baby-shoe size-group pricing,
§4's POS/Stock UI rework (color-chip picker, color×size matrix), §7's
one-screen article-entry flow (draft autosave, copy-article, grid
paste-from-Excel), and reports. Each is substantial enough to deserve its
own test/build/commit cycle rather than being bundled sight-unseen with
the data-model/import foundation.

## §9 Color-in-barcode (new client request)

New ticket: the printed sticker's barcode should encode the article's
color too, so scanning it alone (no need to read the color-code digits by
eye) tells you which color. Read `ArticleBarcode.php`, `Shop.php`'s
template settings, `CatalogSync.php`, `BarcodeLabelController`/
`BarcodeLabelCard.vue`, `Pos/Index.vue`'s `handleBarcode()`, and
`PosController::barcode()` before writing this.

### Design decisions

**A NEW setting, not an extension of `article_barcode_template`.** The
ticket describes "the template" gaining `{COLOR}`/`{SIZE}` tokens, but
`article_barcode_template` already has one clean job — building the
ARTICLE-level barcode (`Product.barcode`, e.g. `SMS-L-10001-NSXXZ`), with
a default every existing shop already relies on unchanged. Overloading
it with color/size tokens that only make sense for a *different* output
(a per-sticker code) would mean `buildArticleBarcode()` has to special-
case stripping tokens it doesn't have values for. Instead: a new
`shops.sticker_barcode_template` (default `{ARTICLE}-{COLOR}-{SIZE}`),
where `{ARTICLE}` is simply the already-built article barcode substituted
whole — so the default resolves to exactly the ticket's own example
(`SMS-L-10001-NSXXZ-SIL`) once `{SIZE}` is stripped. `article_barcode_template`
itself is untouched; zero behavior change to `Product.barcode` generation.

**`{SIZE}` is a toggle, not something the owner types into the template.**
New `shops.barcode_include_size` (boolean, default off). When off,
`{SIZE}` in the template resolves to empty (and the resulting stray
hyphen is cleaned up) — not a half-filled token the owner has to remember
to remove. When on, `{SIZE}` resolves to the real size, and the sticker
barcode becomes byte-identical to what `buildVariantBarcode()` already
produces today — meaning turning the toggle on is a no-op for a shop that
was always printing full per-size barcodes, and the toggle genuinely only
matters for a shop that wants the new, shorter color-grouped stickers.

**Three barcode tiers now, not two.** `ProductVariant.barcode` (exact,
one per size+color — UNCHANGED, never recomputed by this feature, still
the thing `stockPoolMemberIds()` matches on for cross-branch pooling) is
tier 1. `Product.barcode` (article-level, no color — already handled:
scanning it opens the full color×size picker, shipped two passes ago) is
tier 3. New tier 2 — the color-level sticker barcode — sits between them:
scanning it means "this article, this color, which size?" A new table,
**not a column on `ProductVariant`**: every variant of the same color
needs to share the identical value, and a plain unique index on
`product_variants.color_barcode` would reject the 2nd+ variant of a color
group for colliding with the 1st — the exact kind of row-vs-group
mismatch a dedicated table avoids outright.

```
product_color_barcodes: id, shop_id, product_id, color, barcode (unique per shop_id+barcode)
product_color_barcode_aliases: id, shop_id, product_id, color, barcode (unique per shop_id+barcode)
```

A `product_color_barcodes` row's `barcode` is the only thing this feature
ever recomputes (color-code rename, template edit, re-running the
regenerate command) — every time it changes, the OLD value moves to the
aliases table first, never deleted. `ProductVariant.barcode` and
`Product.barcode` are never recomputed by anything in this feature, so
"old already-printed barcodes keep working" is true for them by
construction, not something that needs its own alias mechanism.

**Scan resolution order** (POS, Online Order, and the `PosController::barcode()`
server fallback, all three): (1) `ProductVariant.barcode` exact → add
straight to cart, unchanged from today. (2) `product_color_barcodes` or
`product_color_barcode_aliases` exact match → open `ColorSizePicker`
pre-filtered to that one color (new `onlyColor` prop, hides the color-chip
row); if exactly one size is in stock, skip the picker and add it
directly, same spirit as a tier-1 scan. (3) `Product.barcode` exact →
today's full all-colors picker, unchanged.

**Color codes: suggested, shop-scoped, not chain-wide-validated.** A
3-letter suggestion table (SILVER→SIL, KHAKI→KHK, GOLDEN→GLD, WHITE→WHT,
BLACK→BLK, BROWN→BRN, COFFEE→COF, MAROON→MRN, GREY→GRY — confirmed with
Khaled) feeds a new "রং-কোড সেটিংস" page, editable, uniqueness-checked
against `shops.color_code_map` **within that one shop** — not across
Baby Park and Liberty. `color_code_map` was never synced chain-wide by
`CatalogSync` before this (same as `cost_code_digit_map`), and nothing
about scanning requires it to be: a shop only ever matches barcodes
against its own catalog, so two branches picking different codes for the
same color causes no collision, only a cosmetic inconsistency Khaled can
avoid by setting them the same in both shops' settings himself. Making
that automatic is a real but separate improvement, not needed for
correctness — noted here, not built this pass.

### Build order for this pass

Built: migrations (the two tables + two shop columns); `ArticleBarcode::
buildColorBarcode()` + color-code suggestion helper; wiring into
`ArticleController::store()` and `products:import-articles` (every new
article gets its color-level barcodes alongside the existing per-variant
ones); `articles:regenerate-barcodes {--shop=} {--dry-run}` for
Baby Park's already-imported articles; POS + Online Order scan (tier 2,
online path: in-memory product list, then `PosController::barcode()`
server fallback); `ColorSizePicker`'s new `onlyColor` mode; barcode label
printing (prints the color barcode when the size toggle is off, the
existing per-variant barcode when it's on); the new article form's live
per-color sticker preview; the color-code settings page.

Deliberately deferred (same reasoning as §7's own deferred list — each
is substantial enough to want its own pass): full offline (IndexedDB)
matching for the new tier-2 barcodes (today's offline cache still
resolves tier 1 and tier 3 exactly as before; tier-2 scanning needs a
network round trip until this is built); Return/Exchange and Stock
Count's own scan entry points; sales/stock reports broken down by color;
searching Stock/POS's product search box by color code.

## §10 Short printed-code (live incident, 2026-10-28)

Live report from Khaled: scanning a printed/displayed color or per-size
variant barcode with a phone camera stopped working after this feature
shipped. Measured (Playwright + the real JsBarcode print settings, see
chat for the numbers): the printed module width for a color/variant
barcode is 0.11-0.17mm across every label size this app offers — well
under the ~0.33mm a phone camera needs — and a bigger label isn't an
option (Khaled's printer/roll is a fixed size), and the full descriptive
barcode text must stay on the label exactly as-is (explicit client
requirement, not negotiable). Those two constraints together make the
long descriptive string physically unscannable by a phone camera no
matter what the camera-side code does — confirmed, not guessed.

**Decision**: decouple what gets PRINTED AS TEXT (unchanged — the full
`SMS-L-10001-NSXXZ-KHK-38`-style string, still the real value of
`ProductVariant.barcode`/`Product.barcode`/`ProductColorBarcode.barcode`,
still what search/typing/the hardware scanner/stock-pool matching use,
all completely unchanged) from what gets ENCODED IN THE BARS. The bars
instead encode a short, deterministic code derived from the row's own id
— `ZV{variantId}` for a per-size label, `ZC{colorBarcodeId}` for a
per-color label — reusing the exact naming convention
`BarcodeLabelController::generateVariantBarcode()`/`generateProductBarcode()`
already established (`'ZV'.$variant->id`, `'ZP'.$product->id`) for a
product/variant that never had a barcode at all. No new migration: the id
already exists and is already globally unique (never reused across shops
or after a delete), so `ZV4821`/`C118` resolve via a straight `::find()`
scoped to the current shop — no new table, no alias bookkeeping, nothing
to keep in sync. `JsBarcode`'s own `text` option (separate from what it
encodes) prints the full original string as before; only the symbol
changes. A product-level (no-variant) label keeps encoding its own
`Product.barcode` as-is — that one was already short before this feature
and was never the reported problem, so it's left untouched rather than
switched to `ZP{id}` for no reason.

New tier (checked first, cheap regex, never collides with the existing
`{MFR}-...` formats): `PosController::barcode()` and every scan consumer
(Pos/Index.vue, Clothing/Pos.vue, Online Order's Create.vue) gain a
`^ZV(\d+)$` / `^ZC(\d+)$` check ahead of the existing tier 1/2/3 string
matches, resolving straight to the variant/color-barcode row by id
(tenant-scoped). Everything downstream (add to cart, ColorSizePicker,
offline fallback) is identical to today's tier-1/tier-2 handling — only
how the code is DECODED into "which row" changes.
