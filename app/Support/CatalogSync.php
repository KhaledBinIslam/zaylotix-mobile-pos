<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
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
    /**
     * One catalog for the whole business, whichever shop it was entered
     * at.
     *
     * Khaled: "jekhan tekhe uthuk stock 2 tar ekta ebong etai dekhabe,
     * eta tekhe 2 jon sell korbe". Syncing only ever went main → branch,
     * so an article or a size entered at Liberty stayed at Liberty and
     * the two shops' stock stopped agreeing — which is not a rule a shop
     * owner has any reason to care about.
     *
     * So anything a branch holds on its own is lifted to the main shop
     * first, and only then is the (now complete) catalog pushed back out
     * to every branch. Lifted rather than copied: the main shop's row
     * becomes the master the branch's own row points at, so from the
     * next sync onwards there is one article and one stock figure, and
     * the shared pool keeps both shops' counts in step.
     */
    public static function syncBusiness(Shop $shop): void
    {
        $mainShop = $shop->parent_shop_id
            ? Shop::withoutGlobalScopes()->find($shop->parent_shop_id)
            : $shop;

        if (! $mainShop || ! $mainShop->hasFeature('shared_catalog')) {
            return;
        }

        $branches = Shop::withoutGlobalScopes()->where('parent_shop_id', $mainShop->id)->get();
        if ($branches->isEmpty()) {
            return;
        }

        // every branch's own rows go up first, so the push back down
        // carries them to the OTHER branches too
        foreach ($branches as $branch) {
            static::liftFromBranch($mainShop, $branch);
        }

        foreach ($branches as $branch) {
            static::syncToBranch($mainShop, $branch);
        }
    }

    /**
     * ONE product across the business — the version a single save is
     * allowed to run.
     *
     * syncBusiness() walks every product and every size of every shop.
     * That is right for the owner's "sync catalogue" button and badly
     * wrong on each article save: Baby Park grew to 114 products and
     * 1,404 sizes, so adding one article sat on "Adding…" for fifteen
     * minutes and held the database long enough that editing anything
     * else stopped working too. My own change put it there.
     *
     * This touches the one product that actually changed. Entered at a
     * branch, it is lifted to the main shop first, exactly as
     * syncBusiness does — so "one stock, whichever shop entered it"
     * still holds; it just stops re-checking the other 113 articles to
     * prove it.
     */
    public static function syncOneProduct(Shop $shop, Product $product): void
    {
        $mainShop = $shop->parent_shop_id
            ? Shop::withoutGlobalScopes()->find($shop->parent_shop_id)
            : $shop;

        if (! $mainShop || ! $mainShop->hasFeature('shared_catalog')) {
            return;
        }

        $branches = Shop::withoutGlobalScopes()->where('parent_shop_id', $mainShop->id)->get();
        if ($branches->isEmpty()) {
            return;
        }

        $master = $product;
        if ((int) $product->shop_id !== (int) $mainShop->id) {
            $master = static::liftOneFromBranch($mainShop, $shop, $product);
            if (! $master) {
                return;
            }
        }

        foreach ($branches as $branch) {
            $categoryMap = static::syncCategories($mainShop, $branch);
            $unitMap = static::syncUnits($mainShop, $branch);
            $existing = static::findExistingBranchProduct($branch, $master);
            $branchProduct = static::upsertBranchProduct($mainShop, $branch, $master, $existing, $categoryMap, $unitMap);
            static::syncPriceTiersForProduct($master, $branchProduct);
            static::syncVariantsOfProduct($mainShop, $branch, $master->id, $branchProduct->id);
            static::syncColorBarcodesOfProduct($mainShop, $branch, $master->id, $branchProduct->id);
        }
    }

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

    /**
     * Everything this branch holds that the main shop does not, lifted
     * up so the main shop owns it too.
     *
     * Two shapes of it: a whole product the branch created on its own
     * (master_product_id still null), and a SIZE that a synced product
     * grew at the branch. Both arrive at the main shop with the branch's
     * own stock, because that is the real count of those pairs — under
     * a shared pool there is one figure and both shops read it.
     *
     * Matched the way everything else here is matched: barcode first,
     * since it is kept identical across shops on purpose, else name (for
     * a product) or size+colour (for a size). A barcode already spoken
     * for at the main shop is left off rather than forced; the unique
     * (shop_id, barcode) index would refuse it, and
     * articles:regenerate-barcodes mints a fresh one later.
     */
    private static function liftFromBranch(Shop $mainShop, Shop $branch): void
    {
        foreach (Product::withoutGlobalScopes()->where('shop_id', $branch->id)->whereNull('deleted_at')->get() as $bp) {
            static::liftOneFromBranch($mainShop, $branch, $bp);
        }
    }

    /**
     * One branch product lifted to the main shop, and the master it now
     * points at.
     *
     * Split out of liftFromBranch so a single article save can lift just
     * the article it created — walking the branch's whole catalogue for
     * one new pair of slippers is what put "Adding…" on screen for
     * fifteen minutes.
     *
     * Matched the way everything else here is matched: the existing
     * master link first, then barcode, then name.
     */
    private static function liftOneFromBranch(Shop $mainShop, Shop $branch, Product $bp): ?Product
    {
        $pooled = $mainShop->hasFeature('shared_stock_pool');

        $master = $bp->master_product_id
            ? Product::withoutGlobalScopes()->whereKey($bp->master_product_id)->whereNull('deleted_at')->first()
            : null;

        if (! $master && filled($bp->barcode)) {
            $master = Product::withoutGlobalScopes()->where('shop_id', $mainShop->id)
                ->whereNull('deleted_at')->where('barcode', $bp->barcode)->first();
        }
        if (! $master) {
            $master = Product::withoutGlobalScopes()->where('shop_id', $mainShop->id)
                ->whereNull('deleted_at')->where('name', $bp->name)->first();
        }

        if (! $master) {
            // the branch's category/unit ids mean nothing at the main
            // shop — its own rows are matched by name, the same key
            // syncCategories() uses in the other direction
            $categoryName = $bp->category_id
                ? ProductCategory::withoutGlobalScopes()->whereKey($bp->category_id)->value('name')
                : null;
            $unitName = $bp->unit_id
                ? Unit::withoutGlobalScopes()->whereKey($bp->unit_id)->value('name')
                : null;

            $barcodeFree = filled($bp->barcode) && ! Product::withoutGlobalScopes()
                ->where('shop_id', $mainShop->id)->where('barcode', $bp->barcode)->exists();

            $master = Product::create([
                'shop_id' => $mainShop->id,
                'category_id' => $categoryName
                    ? ProductCategory::withoutGlobalScopes()->where('shop_id', $mainShop->id)->where('name', $categoryName)->value('id')
                    : null,
                'unit_id' => $unitName
                    ? Unit::withoutGlobalScopes()->where('shop_id', $mainShop->id)->where('name', $unitName)->value('id')
                    : null,
                'name' => $bp->name, 'name_en' => $bp->name_en, 'generic_name' => $bp->generic_name,
                'company' => $bp->company, 'shelf_location' => $bp->shelf_location,
                'requires_prescription' => $bp->requires_prescription ?? false, 'emoji' => $bp->emoji ?: '📦',
                'photo_path' => $bp->photo_path,
                'barcode' => $barcodeFree ? $bp->barcode : null,
                'sold_by_weight' => $bp->sold_by_weight ?? false, 'weight_unit' => $bp->weight_unit,
                'cost' => $bp->cost, 'price' => $bp->price, 'wholesale_price' => $bp->wholesale_price,
                'wholesale_moq' => $bp->wholesale_moq, 'discount_price' => $bp->discount_price,
                'reorder_point' => $bp->reorder_point,
                'stock' => $bp->stock,
            ]);
        }

        if ($bp->master_product_id !== $master->id) {
            $bp->forceFill(['master_product_id' => $master->id])->saveQuietly();
        }

        static::liftVariantsFromBranch($mainShop, $branch, $bp, $master, $pooled);

        return $master;
    }

    /** The branch's sizes that the master has no row for — see liftFromBranch(). */
    private static function liftVariantsFromBranch(Shop $mainShop, Shop $branch, Product $bp, Product $master, bool $pooled): void
    {
        $branchVariants = ProductVariant::withoutGlobalScopes()
            ->where('product_id', $bp->id)->whereNull('deleted_at')->orderBy('id')->get();
        if ($branchVariants->isEmpty()) {
            return;
        }

        $masterVariants = ProductVariant::withoutGlobalScopes()
            ->where('product_id', $master->id)->whereNull('deleted_at')->get();
        $created = false;

        foreach ($branchVariants as $bv) {
            $match = (filled($bv->barcode) ? $masterVariants->firstWhere('barcode', $bv->barcode) : null)
                ?? $masterVariants->first(fn (ProductVariant $v) => (string) $v->size === (string) $bv->size && (string) $v->color === (string) $bv->color);

            if ($match) {
                continue;
            }

            $barcodeFree = filled($bv->barcode) && ! ProductVariant::withoutGlobalScopes()
                ->where('shop_id', $mainShop->id)->where('barcode', $bv->barcode)->exists();

            $new = ProductVariant::create([
                'shop_id' => $mainShop->id, 'product_id' => $master->id,
                'size' => $bv->size, 'color' => $bv->color,
                'barcode' => $barcodeFree ? $bv->barcode : null,
                'stock' => $bv->stock, 'reorder_point' => $bv->reorder_point,
                'price' => $bv->price, 'cost' => $bv->cost, 'photo_path' => $bv->photo_path,
            ]);
            $masterVariants->push($new);
            $created = true;
        }

        // products.stock is the live sum of its variants, so lifting a
        // size up changes what the master holds. Written directly, never
        // through applyStockDelta(): that pools the figure across shops,
        // and the branch's own row already counts these pairs — moving
        // it there too would count them twice.
        if ($created) {
            $sum = (float) ProductVariant::withoutGlobalScopes()
                ->where('product_id', $master->id)->whereNull('deleted_at')->sum('stock');
            Product::withoutGlobalScopes()->whereKey($master->id)->update(['stock' => $sum]);
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
                // barcodes would silently diverge for the same category.
                // serial_start rides along for the same reason: the band
                // decides which counter row ArticleSerial reaches, so a
                // branch on a different band would hand out numbers from
                // a range the main shop never uses for that category.
                $existing->update(['name_en' => $cat->name_en, 'emoji' => $cat->emoji, 'code' => $cat->code, 'serial_start' => $cat->serial_start]);
                $map[$cat->id] = $existing->id;
            } else {
                $new = ProductCategory::create(['shop_id' => $branch->id, 'name' => $cat->name, 'name_en' => $cat->name_en, 'emoji' => $cat->emoji, 'code' => $cat->code, 'serial_start' => $cat->serial_start]);
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
        // whereNull('deleted_at') because withoutGlobalScopes() drops the
        // soft-delete scope too: without it, a re-sync would hand every
        // branch a fresh, LIVE copy of an article the owner had deleted
        foreach (Product::withoutGlobalScopes()->whereNull('deleted_at')->where('shop_id', $mainShop->id)->get() as $p) {
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

        // see this class's own docblock -- a brand-new pooled row must start
        // already equal to the main shop's current count, never an empty 0
        // that then has to wait for a stock-changing event to self-correct
        $startingStock = $mainShop->hasFeature('shared_stock_pool') ? (float) $p->stock : 0;

        if ($existing) {
            // stock deliberately excluded -- a branch's own count is never overwritten by a sync
            $wasTrashed = $existing->deleted_at !== null;
            $existing->update($attrs);

            /*
             * A DELETED branch copy was matched, updated, and left
             * deleted — so the branch never got the article back.
             *
             * Khaled deleted B-20007 at Baby Park, which took Liberty's
             * copy down with it, then entered B-20007 again. The sync
             * found that tombstone by name and re-pointed it at the new
             * article without ever reviving it: Baby Park had the shoes,
             * Liberty showed nothing, and the two shops' stock figures
             * stopped agreeing. "2 shop e stock ektai" — so it has to
             * come back.
             *
             * Its stock is reset rather than kept, because the row it is
             * being re-attached to is a DIFFERENT article: the old count
             * belonged to the one that was deleted. That is the same
             * rule a brand-new row already follows, which is what this
             * row now effectively is.
             */
            if ($wasTrashed) {
                $existing->restore();
                $existing->forceFill(['stock' => $startingStock])->saveQuietly();
            }

            return $existing;
        }

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
            static::syncVariantsOfProduct($mainShop, $branch, $mainProductId, $branchProductId);
            static::syncColorBarcodesOfProduct($mainShop, $branch, $mainProductId, $branchProductId);
        }
    }

    /** One product's sizes, so a single article save does not have to walk the whole catalogue — see syncOneProduct(). */
    private static function syncVariantsOfProduct(Shop $mainShop, Shop $branch, int $mainProductId, int $branchProductId): void
    {
        {
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

                // see this class's own docblock -- same starting-stock fix as upsertBranchProduct()
                $startingStock = $mainShop->hasFeature('shared_stock_pool') ? (float) $v->stock : 0;

                if ($existing) {
                    // stock deliberately excluded -- same reasoning as syncProducts
                    $wasTrashed = $existing->deleted_at !== null;
                    $existing->update($attrs);

                    // a removed size coming back with the article, same
                    // reasoning as upsertBranchProduct(): the count it
                    // held belonged to the article that was deleted
                    if ($wasTrashed) {
                        $existing->restore();
                        $existing->forceFill(['stock' => $startingStock])->saveQuietly();
                    }
                } else {
                    ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $branchProductId, 'stock' => $startingStock, ...$attrs]);
                }
            }
        }
    }

    /**
     * The "tier 2" color-level scannable barcode (docs/ARTICLE_SERIES_PLAN.md
     * §9) for every color of one product, plus its alias history — neither
     * table was ever touched by this class before, so a branch's own copy
     * of a product/its sizes existed but its color-barcode stickers always
     * came back "not found" when scanned there (found live: Baby Park's
     * branch could never resolve a color-level sticker at all, variant-tier
     * stickers were unaffected since ProductVariant::barcode IS synced by
     * syncVariantsOfProduct() above). Matched by `color` (unique per
     * product_id+color, same as product_color_barcodes' own DB constraint);
     * the barcode value is kept IDENTICAL across shops on purpose, same
     * reasoning as a variant's barcode — safe because both tables' unique
     * index is scoped to (shop_id, barcode), not barcode alone.
     */
    private static function syncColorBarcodesOfProduct(Shop $mainShop, Shop $branch, int $mainProductId, int $branchProductId): void
    {
        $branchByColor = ProductColorBarcode::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProductId)->get()->keyBy('color');

        foreach (ProductColorBarcode::withoutGlobalScopes()->where('shop_id', $mainShop->id)->where('product_id', $mainProductId)->get() as $cb) {
            $existing = $branchByColor->get($cb->color);
            if ($existing) {
                $existing->update(['barcode' => $cb->barcode]);
            } else {
                ProductColorBarcode::create(['shop_id' => $branch->id, 'product_id' => $branchProductId, 'color' => $cb->color, 'barcode' => $cb->barcode]);
            }
        }

        // append-only history -- every old value the main shop's color ever
        // held also has to resolve at the branch, so a sticker printed
        // before a rename stays scannable there too. Matched by barcode
        // (unique per shop+barcode, same as the live table), never updated.
        $branchBarcodes = ProductColorBarcodeAlias::withoutGlobalScopes()->where('shop_id', $branch->id)->pluck('barcode')->flip();
        foreach (ProductColorBarcodeAlias::withoutGlobalScopes()->where('shop_id', $mainShop->id)->where('product_id', $mainProductId)->get() as $alias) {
            if (! isset($branchBarcodes[$alias->barcode])) {
                ProductColorBarcodeAlias::create(['shop_id' => $branch->id, 'product_id' => $branchProductId, 'color' => $alias->color, 'barcode' => $alias->barcode, 'created_at' => $alias->created_at]);
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
