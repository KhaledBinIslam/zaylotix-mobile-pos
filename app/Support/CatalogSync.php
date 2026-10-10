<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;

/**
 * Copies a main shop's Product/ProductCategory/Unit/ProductVariant rows into
 * a branch's own independent rows — used both at branch creation (branch
 * starts empty) and by the owner-triggered re-sync action later (branch
 * already has rows). Matches existing branch rows by name (products and
 * variants additionally by barcode, when set, since that's a more reliable
 * identifier) — never touches a branch's own `stock`, that stays
 * independently counted per branch always, a new variant always arrives at
 * stock 0. Deliberately best-effort: no batches/serials, no deletion of a
 * branch row that no longer exists on the main shop.
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

    /** @return array<int,int> old category_id => branch's own category_id */
    private static function syncCategories(Shop $mainShop, Shop $branch): array
    {
        $existingByName = ProductCategory::withoutGlobalScopes()->where('shop_id', $branch->id)->get()->keyBy('name');
        $map = [];

        foreach (ProductCategory::withoutGlobalScopes()->where('shop_id', $mainShop->id)->get() as $cat) {
            $existing = $existingByName->get($cat->name);
            if ($existing) {
                $existing->update(['name_en' => $cat->name_en, 'emoji' => $cat->emoji]);
                $map[$cat->id] = $existing->id;
            } else {
                $new = ProductCategory::create(['shop_id' => $branch->id, 'name' => $cat->name, 'name_en' => $cat->name_en, 'emoji' => $cat->emoji]);
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

    /** @return array<int,int> main shop's product_id => branch's own product_id, for syncVariants() */
    private static function syncProducts(Shop $mainShop, Shop $branch, array $categoryMap, array $unitMap): array
    {
        $branchProducts = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->get();
        $byBarcode = $branchProducts->filter(fn (Product $p) => filled($p->barcode))->keyBy('barcode');
        $byName = $branchProducts->keyBy('name');
        $map = [];

        foreach (Product::withoutGlobalScopes()->where('shop_id', $mainShop->id)->get() as $p) {
            $existing = (filled($p->barcode) ? $byBarcode->get($p->barcode) : null) ?? $byName->get($p->name);

            $attrs = [
                'category_id' => $p->category_id ? ($categoryMap[$p->category_id] ?? null) : null,
                'unit_id' => $p->unit_id ? ($unitMap[$p->unit_id] ?? null) : null,
                'name' => $p->name, 'name_en' => $p->name_en, 'generic_name' => $p->generic_name,
                'company' => $p->company, 'shelf_location' => $p->shelf_location,
                'requires_prescription' => $p->requires_prescription, 'emoji' => $p->emoji,
                'photo_path' => $p->photo_path, 'barcode' => $p->barcode,
                'sold_by_weight' => $p->sold_by_weight, 'weight_unit' => $p->weight_unit,
                'cost' => $p->cost, 'price' => $p->price, 'wholesale_price' => $p->wholesale_price,
                'discount_price' => $p->discount_price, 'reorder_point' => $p->reorder_point,
            ];

            if ($existing) {
                // stock deliberately excluded -- a branch's own count is never overwritten by a sync
                $existing->update($attrs);
                $map[$p->id] = $existing->id;
            } else {
                $new = Product::create(['shop_id' => $branch->id, 'stock' => 0, ...$attrs]);
                $map[$p->id] = $new->id;
            }
        }

        return $map;
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

                $attrs = ['size' => $v->size, 'color' => $v->color, 'barcode' => $v->barcode, 'price' => $v->price, 'cost' => $v->cost];

                if ($existing) {
                    // stock deliberately excluded -- same reasoning as syncProducts
                    $existing->update($attrs);
                } else {
                    ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $branchProductId, 'stock' => 0, ...$attrs]);
                }
            }
        }
    }
}
