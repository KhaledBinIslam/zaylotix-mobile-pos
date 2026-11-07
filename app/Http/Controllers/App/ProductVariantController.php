<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\ArticleVariant;
use App\Support\CatalogSync;
use App\Support\ColorBarcodeResolver;
use App\Support\SizeGroupPricing;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A variant (size/color) has its own independent stock — unlike product_units
 * (a pack-size conversion of one shared stock pool). products.stock is kept
 * as the live sum of all its variants' stock, so every store/stockIn/destroy
 * here moves product.stock by the exact same delta it moves the variant by —
 * that invariant is what lets every existing report/alert/valuation query
 * keep reading products.stock unchanged, whether a product has variants or not.
 */
class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            // scoped by shop_id, not a bare unique — see the migration
            // that added this constraint for why (two unrelated shops
            // reusing the same simple code is their own business)
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('product_variants', 'barcode')->where('shop_id', Tenancy::id())],
            'stock' => ['required', 'integer', 'min:0'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'write_off_rest' => ['nullable', 'boolean'],
        ], [
            'barcode.unique' => 'এই বারকোড এই দোকানে আরেকটা ভ্যারিয়েন্টে আগেই ব্যবহার হয়েছে।',
        ]);

        if (empty($data['size']) && empty($data['color'])) {
            return back()->withErrors(['size' => 'সাইজ অথবা রং অন্তত একটা দিন।']);
        }

        // a weighed product's stock is a plain decimal quantity in kg/litre
        // — variant stock is always a whole-unit integer (see the `stock`
        // rule above), so the two models can't coexist on one product
        if ($product->sold_by_weight) {
            return back()->withErrors(['size' => "{$product->name} ওজন/লিটার হিসেবে বিক্রি হয় — এতে ভ্যারিয়েন্ট যোগ করা যাবে না।"]);
        }

        // case/whitespace-insensitive: 'Navy Blue' twinning 'Navy blue' is
        // how Seaman's INV-1001 "stock didn't move" happened
        if (ProductVariant::cellExists($product->id, $data['color'] ?? null, $data['size'] ?? null)) {
            return back()->withErrors(['size' => 'এই সাইজ/রং-এর ভ্যারিয়েন্ট ইতিমধ্যে আছে (বড়/ছোট হাতের তফাত ধরা হয় না)।']);
        }
        $data['size'] = isset($data['size']) ? (trim($data['size']) ?: null) : null;
        $data['color'] = isset($data['color']) ? (trim($data['color']) ?: null) : null;
        if ($data['size'] === null && $data['color'] === null) {
            return back()->withErrors(['size' => 'সাইজ অথবা রং অন্তত একটা দিন।']);
        }

        // A size added to an article AFTER it was created used to go in
        // with no barcode at all, so it could not be scanned or
        // stickered. Only ever filled in when the box was left empty — a
        // hand-typed barcode still wins.
        $shop = Tenancy::shop();
        if ($shop && empty($data['barcode'])) {
            $data['barcode'] = ArticleVariant::freeBarcodeFor(
                $shop, $product, $data['color'] ?? null, $data['size'] ?? null, $data['cost'] ?? null
            );
        }

        DB::transaction(function () use ($product, $data) {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->first();
            $isFirstVariant = ! ProductVariant::where('product_id', $lockedProduct->id)->exists();

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'size' => $data['size'] ?? null,
                'color' => $data['color'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'stock' => $data['stock'],
                'reorder_point' => $data['reorder_point'] ?? null,
                'price' => $data['price'] ?? null,
                'cost' => $data['cost'] ?? null,
            ]);
            // non-article product: freeBarcodeFor gave nothing — mint the
            // same printable ZV{id} the opening-stock grid does, so a new
            // size is never born unprintable ("No barcode"). An ARTICLE
            // stays null on purpose: its barcode encodes the colour code,
            // and articles:regenerate-barcodes fills it in once the owner
            // maps that colour — a ZV here would block that.
            if (! $variant->barcode && ! ArticleVariant::isArticle($product)) {
                $variant->update(['barcode' => 'ZV'.$variant->id]);
            }
            // a line-tracked shop files a variant born WITH stock under the
            // product's default supplier (same convention as SeedSourceLines)
            // — otherwise it starts life untracked and invisible to the
            // supplier filter until someone claims it by hand
            if ((int) $data['stock'] > 0 && Tenancy::shop()?->hasFeature('source_lines')) {
                ProductSource::upsertLine(
                    Tenancy::id(), $lockedProduct->id, $variant->id,
                    $lockedProduct->supplier_id, $lockedProduct->brand_id,
                    (float) $data['stock'],
                    isset($data['cost']) ? (float) $data['cost'] : (float) ($lockedProduct->cost ?? 0),
                    $data['price'] ?? null, (float) ($data['price'] ?? $lockedProduct->price ?? 0)
                );
            }
            \App\Support\IntakeLedger::record($lockedProduct, $variant, (float) $data['stock'], isset($data['cost']) ? (float) $data['cost'] : null, 'variant_add');

            if ($isFirstVariant) {
                $this->reconcilePreExistingStock($lockedProduct, (float) $data['stock'], (bool) ($data['write_off_rest'] ?? false));
            } else {
                $lockedProduct->applyStockDelta($data['stock']);
            }

            Activity::log('product.variant.create', "'{$product->name}'-এ নতুন ভ্যারিয়েন্ট ({$variant->label()}) যোগ করা হয়েছে।", $variant);
        });

        $this->mintColorBarcodes($shop, $product, [$data['color'] ?? null]);
        $this->shareWithTheBusiness($shop, $product);

        return back()->with('success', 'ভ্যারিয়েন্ট যোগ হয়েছে।');
    }

    /**
     * Fills a whole color x size grid in one submit — a clothing shop with
     * 4 colors x 5 sizes shouldn't have to click "add variant" 20 times.
     * Silently skips a cell that's already blank or already an existing
     * variant instead of rejecting the whole grid over one duplicate, since
     * an owner filling a grid will often leave some color/size combinations
     * empty on purpose (a shirt that doesn't come in that size, say).
     */
    public function bulkStore(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.size' => ['nullable', 'string', 'max:50'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            // SEAMAN Phase 3 — a cell's OWN price/cost (epaulettes: every
            // Rank × Type pair sells at its own number). Absent = the
            // grid-level/group/inherited value, exactly as before.
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0'],
            'write_off_rest' => ['nullable', 'boolean'],
            // Khaled: "oi serial e gia product add korle purchase r sell
            // price input diar option nai". One pair for a plain article,
            // one pair per size group for an article priced by group —
            // the same two ways the article form itself offers. Left
            // blank, a variant inherits the article's own price and cost
            // exactly as it did before.
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'group_prices' => ['nullable', 'array'],
            'group_prices.*' => ['nullable', 'numeric', 'min:0'],
            'group_costs' => ['nullable', 'array'],
            'group_costs.*' => ['nullable', 'numeric', 'min:0'],
            // Khaled live: "diffrent color size asle ami kotteke tulbo?
            // ... stock tulle kar nam e uthb?" — the bulk grid now says
            // whose goods these are, instead of silently inheriting the
            // product's default; cash/bank/credit same as every stock-in
            'supplier_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'in:cash,bank,credit'],
        ]);

        if ($product->sold_by_weight) {
            return back()->withErrors(['size' => "{$product->name} ওজন/লিটার হিসেবে বিক্রি হয় — এতে ভ্যারিয়েন্ট যোগ করা যাবে না।"]);
        }

        // case/whitespace-insensitive keys — 'Navy Blue' must never twin
        // 'Navy blue' (Seaman INV-1001); values are stored trimmed too
        $existingByKey = ProductVariant::where('product_id', $product->id)->get()
            ->keyBy(fn ($v) => ProductVariant::cellKey($v->color, $v->size));

        $toCreate = [];
        $toTopUp = []; // [variant id => row]
        $seenKeys = [];
        foreach ($data['variants'] as $row) {
            $row['size'] = isset($row['size']) ? (trim((string) $row['size']) ?: null) : null;
            $row['color'] = isset($row['color']) ? (trim((string) $row['color']) ?: null) : null;
            if ($row['size'] === null && $row['color'] === null) {
                continue;
            }
            $key = ProductVariant::cellKey($row['color'], $row['size']);
            if (in_array($key, $seenKeys, true)) {
                continue; // a duplicate row within this same grid submit
            }
            $seenKeys[] = $key;
            // Khaled live: a cell that already existed was SKIPPED silently
            // and its qty thrown away — "4 ta add korar somoy utlo 2 ...
            // baki product koi". An existing cell is now a stock-in on that
            // variant instead: one grid, whichever cells are new get
            // created, whichever exist get topped up — never silently lost.
            if ($existingByKey->has($key)) {
                if ((int) $row['stock'] > 0) {
                    $toTopUp[$existingByKey->get($key)->id] = $row;
                }

                continue;
            }
            $toCreate[] = $row;
        }

        if (! $toCreate && ! $toTopUp) {
            return back()->withErrors(['size' => 'কোনো ঘরে সংখ্যা পাওয়া যায়নি — অন্তত একটা ঘরে স্টক দিন।']);
        }

        $shop = Tenancy::shop();
        $groupPrices = array_filter($data['group_prices'] ?? []);
        $groupCosts = array_filter($data['group_costs'] ?? []);
        // tenant-scoped find: a foreign id simply becomes null, same as
        // every other supplier/brand field in the app
        $pickedSupplierId = ! empty($data['supplier_id']) ? \App\Models\Supplier::find($data['supplier_id'])?->id : null;
        $pickedBrandId = ! empty($data['brand_id']) ? \App\Models\Brand::find($data['brand_id'])?->id : null;

        // Same refusal as the article form: a size that falls in no
        // group would quietly inherit the article's headline price, and
        // a wrong number printed on a sticker is money. Refusing beats
        // guessing — nothing downstream can tell a guess from a decision.
        if ($shop && ($groupPrices || $groupCosts)) {
            $ungrouped = [];
            foreach (array_merge($toCreate, array_values($toTopUp)) as $row) {
                $size = (string) ($row['size'] ?? '');
                if ($size !== '' && SizeGroupPricing::groupForSize($shop, $size) === null) {
                    $ungrouped[$size] = true;
                }
            }
            if ($ungrouped) {
                $sizes = implode(', ', array_keys($ungrouped));

                return back()->withErrors(['size' => "সাইজ {$sizes} কোনো গ্রুপের রেঞ্জে পড়ছে না — সাইজ-গ্রুপ সেটিংসে রেঞ্জ ঠিক করুন, নয়তো দামের ঘরগুলো খালি রাখুন।"]);
            }
        }

        [$created, $topped] = DB::transaction(function () use ($shop, $product, $toCreate, $toTopUp, $data, $groupPrices, $groupCosts, $pickedSupplierId, $pickedBrandId) {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->first();
            $isFirstVariant = ! ProductVariant::where('product_id', $lockedProduct->id)->exists();

            // a cell's own number beats every broader rule — the owner
            // typed it for exactly this row (SEAMAN Phase 3)
            $resolve = function (array $row, string $which) use ($shop, $groupPrices, $groupCosts, $data) {
                $size = (string) ($row['size'] ?? '');
                $groups = $which === 'price' ? $groupPrices : $groupCosts;

                return $row[$which]
                    ?? ($groups && $shop
                        ? SizeGroupPricing::priceForSize($shop, $size, $groups)
                        : ($data[$which] ?? null));
            };

            $totalStock = 0;
            $totalPaid = 0.0;
            foreach ($toCreate as $row) {
                $rowPrice = $resolve($row, 'price');
                $rowCost = $resolve($row, 'cost');

                $newVariant = ProductVariant::create([
                    'product_id' => $product->id,
                    'size' => $row['size'] ?? null,
                    'color' => $row['color'] ?? null,
                    // this size's own cost is what gets hidden in it, so
                    // the sticker on a 16 reads back what a 16 cost
                    'barcode' => $shop
                        ? ArticleVariant::freeBarcodeFor($shop, $product, $row['color'] ?? null, $row['size'] ?? null, $rowCost)
                        : null,
                    'stock' => $row['stock'],
                    'price' => $rowPrice,
                    'cost' => $rowCost,
                ]);
                // Khaled live ("No barcode" on every grid-born variant):
                // a non-article product gets no code from freeBarcodeFor —
                // fall back to the same printable ZV{id} the opening-stock
                // grid has always minted. An article stays null on purpose
                // (see store() — regenerate-barcodes fills it in later).
                if (! $newVariant->barcode && ! ArticleVariant::isArticle($product)) {
                    $newVariant->update(['barcode' => 'ZV'.$newVariant->id]);
                }
                // a grid cell born with stock goes under the supplier/brand
                // picked ON the grid, falling back to the product's own
                // default — "stock tulle kar nam e uthb" always has an answer
                $lineCost = $rowCost !== null ? (float) $rowCost : (float) ($lockedProduct->cost ?? 0);
                if ((int) $row['stock'] > 0 && $shop?->hasFeature('source_lines')) {
                    ProductSource::upsertLine(
                        Tenancy::id(), $lockedProduct->id, $newVariant->id,
                        $pickedSupplierId ?? $lockedProduct->supplier_id,
                        $pickedBrandId ?? $lockedProduct->brand_id,
                        (float) $row['stock'], $lineCost,
                        $rowPrice !== null ? (float) $rowPrice : null,
                        (float) ($rowPrice ?? $lockedProduct->price ?? 0)
                    );
                }
                \App\Support\IntakeLedger::record($lockedProduct, $newVariant, (float) $row['stock'], $rowCost !== null ? (float) $rowCost : null, 'variant_add', $pickedSupplierId);
                $totalStock += (int) $row['stock'];
                $totalPaid += (int) $row['stock'] * $lineCost;
            }

            // existing cells: a stock-in on that variant, same math as the
            // dedicated stock-in endpoints — never a silent skip
            foreach ($toTopUp as $variantId => $row) {
                $locked = ProductVariant::whereKey($variantId)->lockForUpdate()->first();
                if (! $locked) {
                    continue; // deleted between read and lock — nothing to top up
                }
                $qty = (int) $row['stock'];
                $rowPrice = $resolve($row, 'price');
                $rowCost = $resolve($row, 'cost');
                $lineCost = $rowCost !== null ? (float) $rowCost : (float) ($locked->cost ?? $lockedProduct->cost ?? 0);

                $line = null;
                if ($shop?->hasFeature('source_lines')) {
                    $line = ProductSource::upsertLine(
                        Tenancy::id(), $lockedProduct->id, $locked->id,
                        $pickedSupplierId ?? $lockedProduct->supplier_id,
                        $pickedBrandId ?? $lockedProduct->brand_id,
                        (float) $qty, $lineCost,
                        $rowPrice !== null ? (float) $rowPrice : null,
                        (float) ($locked->price ?? $lockedProduct->price ?? 0)
                    );
                    $locked->cost = $line->cost; // mirror rides the line, same as every stock-in
                } elseif ($rowCost !== null) {
                    // same blend-or-overwrite the "+" button uses
                    $oldStock = (float) $locked->stock;
                    $oldCost = (float) ($locked->cost ?? $lockedProduct->cost ?? 0);
                    $usesAverage = $shop?->usesWeightedAverageCosting();
                    $locked->cost = ($usesAverage && ($oldStock + $qty) > 0 && $oldStock > 0)
                        ? round((($oldStock * $oldCost) + ($qty * (float) $rowCost)) / ($oldStock + $qty), 2)
                        : $rowCost;
                }
                if ($rowPrice !== null) {
                    $locked->price = $rowPrice;
                }
                $locked->applyStockDelta($qty);
                $locked->save();
                \App\Support\IntakeLedger::record($lockedProduct, $locked, (float) $qty, $rowCost !== null ? (float) $rowCost : null, 'variant_stock_in', $pickedSupplierId, $line?->id);
                $totalStock += $qty;
                $totalPaid += $qty * $lineCost;
            }

            if ($isFirstVariant) {
                $this->reconcilePreExistingStock($lockedProduct, (float) $totalStock, (bool) ($data['write_off_rest'] ?? false));
            } else {
                $lockedProduct->applyStockDelta($totalStock);
            }

            // same convention as every other stock-in with a supplier picked:
            // cash/bank settles now, credit grows the supplier's payable
            \App\Support\SupplierPayable::apply($pickedSupplierId, $data['payment_method'] ?? null, $totalPaid);

            Activity::log('product.variant.bulkCreate', "'{$product->name}'-এ গ্রিড থেকে ".count($toCreate).'টা নতুন ভ্যারিয়েন্ট, '.count($toTopUp).'টা আগের ঘরে স্টক যোগ হয়েছে।', $product);

            return [count($toCreate), count($toTopUp)];
        });

        $this->mintColorBarcodes($shop, $product, array_column($toCreate, 'color'));
        $this->shareWithTheBusiness($shop, $product);

        $msg = $created ? "{$created}টা নতুন ভ্যারিয়েন্ট যোগ হয়েছে" : '';
        if ($topped) {
            $msg .= ($msg ? ', ' : '')."{$topped}টা আগের ঘরে স্টক যোগ হয়েছে";
        }

        return back()->with('success', $msg.'।');
    }

    /**
     * docs/ARTICLE_SERIES_PLAN.md §9 — the colour-level "tier 2" sticker
     * barcode, which ArticleController::store() mints for every colour an
     * article is created with. A colour introduced later had none, so its
     * label printed nothing scannable. Idempotent: ColorBarcodeResolver
     * aliases rather than overwrites, which is what lets
     * articles:regenerate-barcodes be re-run at any time.
     */
    /**
     * A size added at either shop belongs to the whole business.
     *
     * Khaled: "jekhan tekhe uthuk stock 2 tar ekta ebong etai dekhabe,
     * eta tekhe 2 jon sell korbe". A size entered while viewing the
     * branch used to stay at the branch, and the two shops' stock
     * figures drifted apart. CatalogSync::syncBusiness() is a no-op for
     * a shop with no branches or without shared_catalog, which is every
     * other shop in the system.
     */
    private function shareWithTheBusiness(?Shop $shop, Product $product): void
    {
        if ($shop) {
            // this product only — syncBusiness() walks the entire
            // catalogue of every shop, which is minutes of work to carry
            // one new size across
            CatalogSync::syncOneProduct($shop, $product);
        }
    }

    private function mintColorBarcodes(?Shop $shop, Product $product, array $colors): void
    {
        if (! $shop || ! ArticleVariant::isArticle($product)) {
            return;
        }

        foreach (array_unique(array_filter($colors)) as $color) {
            ColorBarcodeResolver::resolve($shop, $product, $color, $product->barcode);
        }
    }

    /**
     * The first variant(s) ever created on a product that already had some
     * `stock` (added before any size/color existed, or before this product
     * was ever meant to track variants) must never just ADD the new
     * variant's stock on top — that's exactly the bug that produced the
     * Salenoir mismatch (products.stock 40 vs variants summing to 28; the
     * missing 12 was pre-existing, unallocated stock from before variants
     * existed). Instead the owner must account for every pre-existing unit:
     * either the new variant(s) stock sums to exactly the pre-existing
     * total (it was all this size/color), or whatever's short is explicitly
     * written off as Damage (genuinely unaccounted-for stock, not silently
     * discarded) — never left as a silent gap between products.stock and
     * SUM(variants.stock) with no record of why.
     */
    private function reconcilePreExistingStock(Product $lockedProduct, float $allocatedStock, bool $writeOffRest): void
    {
        $preExisting = (float) $lockedProduct->stock;

        if ($preExisting <= 0) {
            // nothing to reconcile — this product never had unallocated
            // stock, so the new variant(s) just add normally, exactly as
            // every later (non-first) variant addition already does
            $lockedProduct->applyStockDelta($allocatedStock);

            return;
        }

        if ($allocatedStock > $preExisting) {
            throw ValidationException::withMessages([
                'stock' => "মোট ভাগ করা স্টক ({$allocatedStock}) আগের স্টকের ({$preExisting}) চেয়ে বেশি হতে পারবে না। আগে স্টক-ইন করে সঠিক সংখ্যা ঠিক করুন, তারপর ভ্যারিয়েন্ট যোগ করুন।",
            ]);
        }

        $shortfall = round($preExisting - $allocatedStock, 3);

        if ($shortfall > 0 && ! $writeOffRest) {
            throw ValidationException::withMessages([
                'stock' => "এই পণ্যে আগে থেকে {$preExisting} স্টক আছে (কোনো সাইজ/রং ছাড়া) — নতুন ভ্যারিয়েন্ট(গুলো)-এর স্টক মিলিয়ে ঠিক {$preExisting} হতে হবে, অথবা বাকি {$shortfall} 'নষ্ট' হিসেবে ধরার অপশনটা বেছে নিন।",
            ]);
        }

        if ($shortfall > 0) {
            Damage::create([
                'product_id' => $lockedProduct->id,
                'qty' => $shortfall,
                'reason' => 'ভ্যারিয়েন্ট যোগ করার সময় আগের unallocated স্টকের বাকি অংশ নষ্ট/অজানা হিসেবে ধরা হয়েছে',
                'loss' => $shortfall * (float) $lockedProduct->cost,
                'date' => now()->toDateString(),
            ]);

            $lockedProduct->applyStockDelta(-$shortfall);

            Activity::log('product.variant.writeOffPreExistingStock', "'{$lockedProduct->name}'-এর আগের unallocated স্টক থেকে {$shortfall} ইউনিট নষ্ট হিসেবে রেকর্ড করা হয়েছে (ভ্যারিয়েন্ট যোগ করার সময়)।", $lockedProduct, ['shortfall' => $shortfall]);
        }

        // $allocatedStock === $preExisting exactly, or the shortfall was
        // just written off down to match — either way products.stock is
        // now already correct and must NOT be incremented again
    }

    /**
     * SkyBuy-style color picker (docs/ARTICLE_SERIES_PLAN.md §4) — one
     * photo per color, applied to every variant sharing it so the picker's
     * grouping (resources/js/support/productDisplay.js colorGroups()) finds
     * it regardless of which specific size the shopper taps first.
     */
    public function updateColorPhoto(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'color' => ['required', 'string', 'max:50'],
            'photo' => ['required', 'image', 'max:3072'],
        ]);

        $path = $request->file('photo')->store('product-photos', 'public');

        ProductVariant::where('product_id', $product->id)->where('color', $data['color'])->update(['photo_path' => $path]);

        return back()->with('success', "'{$data['color']}' রঙের ছবি সংরক্ষণ করা হয়েছে।");
    }

    public function update(Request $request, ProductVariant $productVariant)
    {
        $this->authorize('update', $productVariant->product);

        $data = $request->validate([
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('product_variants', 'barcode')->where('shop_id', Tenancy::id())->ignore($productVariant->id)],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'brand_id' => ['nullable', 'integer'], // Phase 4 — the helmet case: brand per variant
        ], [
            'barcode.unique' => 'এই বারকোড এই দোকানে আরেকটা ভ্যারিয়েন্টে আগেই ব্যবহার হয়েছে।',
        ]);

        if (empty($data['size']) && empty($data['color'])) {
            return back()->withErrors(['size' => 'সাইজ অথবা রং অন্তত একটা দিন।']);
        }

        // feature-gated + tenant-checked, same rule as the product's own
        // brand field; absent key leaves the column exactly as it was
        if (array_key_exists('brand_id', $data)) {
            if (Tenancy::shop()?->hasFeature('brands')) {
                $data['brand_id'] = ! empty($data['brand_id']) ? \App\Models\Brand::find($data['brand_id'])?->id : null;
            } else {
                unset($data['brand_id']);
            }
        }

        $productVariant->update($data);

        return back()->with('success', 'ভ্যারিয়েন্ট আপডেট হয়েছে।');
    }

    /** Adds received qty to this specific variant — mirrors ProductController::stockIn but per-variant. */
    public function stockIn(Request $request, ProductVariant $productVariant)
    {
        $this->authorize('update', $productVariant->product);

        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            // Phase 4 — who it came from; tenant-scoped find below, a
            // foreign id simply becomes "no supplier" (Nil)
            'supplier_id' => ['nullable', 'integer'],
            // Khaled live: a supplier picked here never affected what's
            // owed to them — only the separate Purchases module tracked
            // that. 'cash'/'bank' settles it on the spot (decrements the
            // shop's own balance, same as Purchases); 'credit' adds to
            // the supplier's payable. Absent (no supplier, or an owner
            // who hasn't touched this yet) is a no-op — see SupplierPayable.
            'payment_method' => ['nullable', 'in:cash,bank,credit'],
        ]);

        $supplierId = ! empty($data['supplier_id']) ? \App\Models\Supplier::find($data['supplier_id'])?->id : null;

        DB::transaction(function () use ($productVariant, $data, $supplierId) {
            // null if a concurrent destroy() already soft-deleted this
            // variant while this request was waiting on the row lock — a
            // 422 here, not a fatal error calling a method on null
            $locked = ProductVariant::whereKey($productVariant->id)->lockForUpdate()->first();
            if (! $locked) {
                abort(422, 'এই ভ্যারিয়েন্টটি ইতিমধ্যে মুছে ফেলা হয়েছে।');
            }

            // Khaled live: this quick "+" button (with the "quick supplier"
            // picker above the variant list) updated stock, the ledger and
            // SupplierPayable, but never wrote a LINE — so on a line-tracked
            // shop, stock added this way was invisible to the supplier
            // filter/search and the per-lot display ("alda supllier dia
            // product tulchi but track hoini"). Same shape as
            // ProductSourceController::applyStockIn(): the line blends its
            // own cost, and the mirror cost OVERWRITES to the line's cost
            // (never a cross-supplier blend). A shop without source_lines
            // keeps the pre-existing logic below, completely untouched.
            $line = null;
            if (\App\Support\Tenancy::shop()?->hasFeature('source_lines')) {
                $paidCostForLine = isset($data['cost']) ? (float) $data['cost'] : (float) ($locked->cost ?? $locked->product->cost ?? 0);
                $line = ProductSource::upsertLine(
                    \App\Support\Tenancy::id(), $locked->product_id, $locked->id,
                    $supplierId, null, (float) $data['qty'], $paidCostForLine, null,
                    (float) ($locked->price ?? $locked->product->price ?? 0)
                );
                $newCost = $line->cost;
            } else {
                /*
                 * Cost on restock, two shops' two truths (Phase 4):
                 * weighted_average_costing blends the new cost into a moving
                 * average — the same formula StockIn::apply has always used
                 * for plain products — so 5 @1600 then 5 @1750 values the
                 * shelf at 1675. Without the feature the new cost OVERWRITES,
                 * exactly as before: Baby Park's stickers encode the cost, so
                 * their variant.cost must stay the whole number printed on
                 * the label, never a blended fraction.
                 */
                $newCost = $locked->cost;
                if (isset($data['cost'])) {
                    $oldStock = (float) $locked->stock;
                    $oldCost = (float) ($locked->cost ?? $locked->product->cost ?? 0);
                    $usesAverage = \App\Support\Tenancy::shop()?->usesWeightedAverageCosting();
                    $newCost = ($usesAverage && ($oldStock + $data['qty']) > 0 && $oldStock > 0)
                        ? round((($oldStock * $oldCost) + ($data['qty'] * (float) $data['cost'])) / ($oldStock + $data['qty']), 2)
                        : $data['cost'];
                }
            }

            $locked->applyStockDelta($data['qty']);
            $locked->update(['cost' => $newCost]);

            $product = Product::whereKey($locked->product_id)->lockForUpdate()->first();
            $product?->applyStockDelta($data['qty']);
            if ($line && $product) {
                // mirror cost rides the line's cost on a line-tracked shop,
                // same as the dedicated 🚚 stock-in does
                $product->update(['cost' => $line->cost]);
            }
            if ($product) {
                // the ledger always records the price actually PAID, never the blended average
                \App\Support\IntakeLedger::record($product, $locked, (float) $data['qty'], isset($data['cost']) ? (float) $data['cost'] : null, 'variant_stock_in', $supplierId, $line?->id);
            }

            $paidCost = isset($data['cost']) ? (float) $data['cost'] : (float) $locked->cost;
            \App\Support\SupplierPayable::apply($supplierId, $data['payment_method'] ?? null, $paidCost * $data['qty']);

            Activity::log('product.variant.stockIn', "ভ্যারিয়েন্ট '{$locked->label()}'-এ {$data['qty']} স্টক যোগ করা হয়েছে।", $locked);
        });

        return back()->with('success', 'স্টক আপডেট হয়েছে।');
    }

    public function destroy(ProductVariant $productVariant)
    {
        $this->authorize('delete', $productVariant->product);

        DB::transaction(function () use ($productVariant) {
            // null if a concurrent destroy() already won this race — just a
            // no-op then, the variant's already gone and stock already
            // adjusted, not an error
            $locked = ProductVariant::whereKey($productVariant->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            // keep products.stock's "sum of variants" invariant correct —
            // this variant's remaining stock leaves with it
            $product = Product::whereKey($locked->product_id)->lockForUpdate()->first();
            $product?->applyStockDelta(-$locked->stock);

            $locked->delete(); // soft delete — keeps historical sale_items intact
        });

        return back()->with('success', 'ভ্যারিয়েন্ট মুছে ফেলা হয়েছে।');
    }
}
