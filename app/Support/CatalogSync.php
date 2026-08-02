<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;

/**
 * Copies a main shop's Product/ProductCategory/Unit rows into a branch's own
 * independent rows — used both at branch creation (branch starts empty) and
 * by the owner-triggered re-sync action later (branch already has rows).
 * Matches existing branch rows by name (products additionally by barcode,
 * when set, since that's a more reliable identifier) — never touches a
 * branch's own `stock`, that stays independently counted per branch always.
 * Deliberately best-effort: no variants/batches/serials, no deletion of a
 * branch row that no longer exists on the main shop.
 */
class CatalogSync
{
    public static function syncToBranch(Shop $mainShop, Shop $branch): void
    {
        $categoryMap = static::syncCategories($mainShop, $branch);
        $unitMap = static::syncUnits($mainShop, $branch);
        static::syncProducts($mainShop, $branch, $categoryMap, $unitMap);
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

    private static function syncProducts(Shop $mainShop, Shop $branch, array $categoryMap, array $unitMap): void
    {
        $branchProducts = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->get();
        $byBarcode = $branchProducts->filter(fn (Product $p) => filled($p->barcode))->keyBy('barcode');
        $byName = $branchProducts->keyBy('name');

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
            } else {
                Product::create(['shop_id' => $branch->id, 'stock' => 0, ...$attrs]);
            }
        }
    }
}
