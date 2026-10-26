<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;

/**
 * Copies a main shop's Product/ProductCategory/Unit/ProductVariant rows into
 * a branch's own independent rows — used both at branch creation (branch
 * starts empty), by the owner-triggered re-sync action later (branch
 * already has rows), and — see docs/FIX_PLAN.md's "শেয়ার্ড ক্যাটালগ" design
 * note — automatically, in real time, from ProductController::store()/
 * update() for a shop with the opt-in `shared_catalog` feature. Matches
 * existing branch rows by `master_product_id` first (an explicit, durable
 * link set the first time a product is ever synced — survives a later
 * rename on either side), falling back to barcode/name for a row that's
 * never been synced before (first-ever sync, or a legacy branch product
 * created before this link existed) — an EXISTING branch row's `stock` is
 * never touched by a sync, that stays independently counted per branch (or
 * kept in lockstep by Product/ProductVariant::applyStockDelta()'s own
 * pooling, if `shared_stock_pool` is also on — never written here).
 *
 * A brand-new row is the one exception: with `shared_stock_pool` OFF it
 * still arrives at stock 0 (to be physically counted in), but with it ON,
 * starting at 0 would be actively wrong — there's only ONE real stock
 * number for a pooled product, so a freshly-synced branch copy must start
 * already equal to the main shop's current count, not an empty shelf that
 * happens to share a product row. (Real bug Khaled hit: a newly-imported
 * article's branch copy sat at 0 until its first sale/stock-in anywhere in
 * the pool happened to correct it — meaning the branch's POS couldn't
 * sell it at all until then.) Deliberately best-effort otherwise: no
 * batches/serials, no deletion of a branch row that no longer exists on
 * the main shop.
 */
class CatalogSync
{
    public static function syncToBranch(Shop $mainShop, Shop $branch): void
    {
        $categoryMap = static::syncCategories($mainShop, $branch);
        $unitMap = static::syncUnits($mainShop, $branch);
        $productMap = static::syncProducts($mainShop, $branch, $categoryMap, $unitMap);
        static::syncVariants($mainShop, $branch, $productMap);
    }

    /**
     * Real-time counterpart to syncToBranch() — propagates ONE just-saved
     * main-shop product to every sibling branch immediately, instead of
     * waiting for the owner to remember to press "ক্যাটালগ সিঙ্ক করুন".
     * Deliberately product-only (no variant sync here) to keep a single
     * product save cheap — variant changes still go out on the next full
     * manual sync, same as before this existed.
     */
    public static function syncProductToBranches(Shop $mainShop, Product $product): void
    {
        $branches = Shop::withoutGlobalScopes()->where('parent_shop_id', $mainShop->id)->get();

        foreach ($branches as $branch) {
            $categoryMap = static::syncCategories($mainShop, $branch);
            $unitMap = static::syncUnits($mainShop, $branch);
            $existing = static::findExistingBranchProduct($branch, $product);
            $branchProduct = static::upsertBranchProduct($mainShop, $branch, $product, $existing, $categoryMap, $unitMap);
            static::syncPriceTiersForProduct($product, $branchProduct);
        }
    }

    /** @return array<int,int> old category_id => branch's own category_id */
    private static function syncCategories(Shop $mainShop, Shop $branch): array
    {
        $existingByName = ProductCategory::withoutGlobalScopes()->where('shop_id', $branch->id)->get()->keyBy('name');
        $map = [];

        foreach (ProductCategory::withoutGlobalScopes()->where('shop_id', $mainShop->id)->get() as $cat) {
            $existing = $existingByName->get($cat->name);
            if ($existing) {
                // code (docs/ARTICLE_SERIES_PLAN.md §2) kept in sync too —
                // a branch's "Ladies" must carry the SAME article-barcode
                // category code as the main shop's, or the two shops'
                // barcodes would silently diverge for the same category
                $existing->update(['name_en' => $cat->name_en, 'emoji' => $cat->emoji, 'code' => $cat->code]);
                $map[$cat->id] = $existing->id;
            } else {
                $new = ProductCategory::create(['shop_id' => $branch->id, 'name' => $cat->name, 'name_en' => $cat->name_en, 'emoji' => $cat->emoji, 'code' => $cat->code]);
                $map[$cat->id] = $new->id;
            }
        }

        return $map;
    }

    /** @return array<int,int> old unit_id => branch's own unit_id */
    private static function syncUnits(Shop $mainShop, Shop $branch): array
    {
        $existingByName = Unit::withoutGlobalScopes()->where('shop_id', $branch->id)->get()->keyBy('name');
        $map = [];

        foreach (Unit::withoutGlobalScopes()->where('shop_id', $mainShop->id)->get() as $unit) {
            $existing = $existingByName->get($unit->name);
            if ($existing) {
                $existing->update(['name_en' => $unit->name_en, 'code' => $unit->code]);
                $map[$unit->id] = $existing->id;
            } else {
                $new = Unit::create(['shop_id' => $branch->id, 'name' => $unit->name, 'name_en' => $unit->name_en, 'code' => $unit->code]);
                $map[$unit->id] = $new->id;
            }
        }

        return $map;
    }

    /**
     * @return array<int,int> main shop's product_id => branch's own product_id, for syncVariants()
     *
     * Prefetches the branch's whole catalog once (not once per main-shop
     * product — this loop can run over thousands of rows) and matches
     * in-memory, same three-tier precedence as findExistingBranchProduct()
     * uses for the single-product real-time path.
     */
    private static function syncProducts(Shop $mainShop, Shop $branch, array $categoryMap, array $unitMap): array
    {
        $branchProducts = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->get();
        $byMasterId = $branchProducts->whereNotNull('master_product_id')->keyBy('master_product_id');
        $byBarcode = $branchProducts->filter(fn (Product $p) => filled($p->barcode))->keyBy('barcode');
        $byName = $branchProducts->keyBy('name');

        $map = [];
        foreach (Product::withoutGlobalScopes()->where('shop_id', $mainShop->id)->get() as $p) {
            $existing = $byMasterId->get($p->id)
                ?? (filled($p->barcode) ? $byBarcode->get($p->barcode) : null)
                ?? $byName->get($p->name);

            $branchProduct = static::upsertBranchProduct($mainShop, $branch, $p, $existing, $categoryMap, $unitMap);
            static::syncPriceTiersForProduct($p, $branchProduct);
            $map[$p->id] = $branchProduct->id;
        }

        return $map;
    }

    /**
     * Single-row equivalent of syncProducts()' in-memory matching, for the
     * real-time syncProductToBranches() path — one product, so a plain
     * query (rather than prefetching the whole branch catalog) is the
     * cheaper option here.
     */
    private static function findExistingBranchProduct(Shop $branch, Product $p): ?Product
    {
        return Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $p->id)->first()
            ?? Product::withoutGlobalScopes()->where('shop_id', $branch->id)
                ->where(fn ($q) => filled($p->barcode)
                    ? $q->where('barcode', $p->barcode)->orWhere('name', $p->name)
                    : $q->where('name', $p->name))
                ->first();
    }

    /**
     * The actual create-or-update, shared by both the bulk syncProducts()
     * loop and the real-time syncProductToBranches() path — `$existing` is
     * found differently by each caller (in-memory map vs. a direct query)
     * but applied identically here. `master_product_id` is always stamped
     * going forward (set by a previous sync, from either path, or freshly
     * here on a first-ever match) — durable across a later rename on
     * either side, unlike barcode/name alone.
     */
    private static function upsertBranchProduct(Shop $mainShop, Shop $branch, Product $p, ?Product $existing, array $categoryMap, array $unitMap): Product
    {
        // emoji/requires_prescription/sold_by_weight fall back here because
        // a Product instance fresh off Product::create() never reflects a
        // column's DB-level default (migration defaults only apply when the
        // INSERT omits the column entirely — $p's own in-memory attribute
        // stays null unless it was explicitly passed in). Every real product
        // created through ProductController::store() always sets these
        // explicitly, so this only ever matters for a product built
        // directly (tests, console seeders) — never silently insert a NULL
        // into a NOT NULL column on the branch side.
        $attrs = [
            'category_id' => $p->category_id ? ($categoryMap[$p->category_id] ?? null) : null,
            'unit_id' => $p->unit_id ? ($unitMap[$p->unit_id] ?? null) : null,
            'name' => $p->name, 'name_en' => $p->name_en, 'generic_name' => $p->generic_name,
            'company' => $p->company, 'shelf_location' => $p->shelf_location,
            'requires_prescription' => $p->requires_prescription ?? false, 'emoji' => $p->emoji ?: '📦',
            'photo_path' => $p->photo_path, 'barcode' => $p->barcode,
            'sold_by_weight' => $p->sold_by_weight ?? false, 'weight_unit' => $p->weight_unit,
            'cost' => $p->cost, 'price' => $p->price, 'wholesale_price' => $p->wholesale_price,
            'wholesale_moq' => $p->wholesale_moq,
            'discount_price' => $p->discount_price, 'reorder_point' => $p->reorder_point,
            'master_product_id' => $p->id,
        ];

        if ($existing) {
            // stock deliberately excluded -- a branch's own count is never overwritten by a sync
            $existing->update($attrs);

            return $existing;
        }

        // see this class's own docblock -- a brand-new pooled row must start
        // already equal to the main shop's current count, never an empty 0
        // that then has to wait for a stock-changing event to self-correct
        $startingStock = $mainShop->hasFeature('shared_stock_pool') ? (float) $p->stock : 0;

        return Product::create(['shop_id' => $branch->id, 'stock' => $startingStock, ...$attrs]);
    }

    /**
     * Size/color/barcode/price/cost for every variant of every synced
     * product — matched the same way as everything else in this class
     * (barcode first, else size+color), branch variant stock never touched,
     * a brand-new variant arrives at stock 0 (to be physically counted in).
     * A variant's barcode is deliberately kept IDENTICAL across branches —
     * so the same printed sticker scans to the same size everywhere — which
     * is safe precisely because product_variants' unique index is scoped to
     * (shop_id, barcode), not barcode alone (see
     * 2026_10_10_000001_add_unique_index_to_product_variants_barcode.php):
     * two different branches (two different shop_id's) each having their
     * own variant row with the same barcode string is not a collision.
     *
     * @param  array<int,int>  $productMap  main product_id => branch product_id
     */
    private static function syncVariants(Shop $mainShop, Shop $branch, array $productMap): void
    {
        foreach ($productMap as $mainProductId => $branchProductId) {
            $branchVariants = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProductId)->get();
            $byBarcode = $branchVariants->filter(fn (ProductVariant $v) => filled($v->barcode))->keyBy('barcode');
            $bySizeColor = $branchVariants->keyBy(fn (ProductVariant $v) => $v->size.'|'.$v->color);

            foreach (ProductVariant::withoutGlobalScopes()->where('shop_id', $mainShop->id)->where('product_id', $mainProductId)->get() as $v) {
                $existing = (filled($v->barcode) ? $byBarcode->get($v->barcode) : null) ?? $bySizeColor->get($v->size.'|'.$v->color);

                // photo_path (docs/ARTICLE_SERIES_PLAN.md §4's color picker)
                // and reorder_point synced the same way as everything else
                // here — a color's photo, and its own low-stock threshold,
                // set on the main shop should show up on the branch too
                $attrs = ['size' => $v->size, 'color' => $v->color, 'barcode' => $v->barcode, 'price' => $v->price, 'cost' => $v->cost, 'photo_path' => $v->photo_path, 'reorder_point' => $v->reorder_point];

                if ($existing) {
                    // stock deliberately excluded -- same reasoning as syncProducts
                    $existing->update($attrs);
                } else {
                    // see this class's own docblock -- same starting-stock fix as upsertBranchProduct()
                    $startingStock = $mainShop->hasFeature('shared_stock_pool') ? (float) $v->stock : 0;
                    ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $branchProductId, 'stock' => $startingStock, ...$attrs]);
                }
            }
        }
    }

    /**
     * Wholesale quantity-tier rows (Phase 30 / wholesale_tiers) are owned by
     * the main shop the same way wholesale_price already is — matched by
     * min_qty (there's nothing else to match a tier row by), synced on
     * every product save exactly like a variant's size+color match above. A
     * branch tier whose min_qty no longer exists on the main shop's product
     * is deleted — tiers have no independent branch-local meaning the way a
     * variant's own stock does, so there's nothing worth preserving.
     */
    private static function syncPriceTiersForProduct(Product $mainProduct, Product $branchProduct): void
    {
        $branchTiers = ProductPriceTier::withoutGlobalScopes()->where('shop_id', $branchProduct->shop_id)->where('product_id', $branchProduct->id)->get()->keyBy('min_qty');
        $keptMinQtys = [];

        foreach (ProductPriceTier::withoutGlobalScopes()->where('shop_id', $mainProduct->shop_id)->where('product_id', $mainProduct->id)->get() as $tier) {
            $keptMinQtys[] = $tier->min_qty;
            $existing = $branchTiers->get($tier->min_qty);

            if ($existing) {
                $existing->update(['price' => $tier->price]);
            } else {
                ProductPriceTier::create(['shop_id' => $branchProduct->shop_id, 'product_id' => $branchProduct->id, 'min_qty' => $tier->min_qty, 'price' => $tier->price]);
            }
        }

        ProductPriceTier::withoutGlobalScopes()->where('shop_id', $branchProduct->shop_id)->where('product_id', $branchProduct->id)
            ->whereNotIn('min_qty', $keptMinQtys)
            ->delete();
    }
}
