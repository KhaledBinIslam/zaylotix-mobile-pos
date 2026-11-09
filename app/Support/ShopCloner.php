<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;

/**
 * Clones a source shop's catalog (categories/units/products/variants) and
 * shop-level settings into a brand-new, fully independent shop — Liberty
 * Bali's "same article/barcode setup as Baby Park, starting from an empty
 * shelf" request.
 *
 * Deliberately NOT built on CatalogSync::syncToBranch(): that method starts
 * a new row's stock at the SOURCE shop's current count whenever the source
 * has the shared_stock_pool feature on (Baby Park does), because it's
 * written for an actual branch sharing one pooled figure with its main
 * shop. Here the target is a physically different, independent shop
 * starting empty — stock must always be 0, so this never calls that method
 * and never shares its stock-starting logic.
 *
 * The target shop must already be freshly provisioned and have its
 * generic ShopProvisioner seed categories/units deleted before this runs —
 * every method here only ever creates, it never matches/updates an
 * existing row (unlike CatalogSync, which has to handle re-syncing an
 * already-populated branch).
 */
class ShopCloner
{
    /**
     * Features that only make sense between a main shop and its own
     * branches — Liberty Bali has none, so carrying these over would be
     * meaningless (shared_stock_pool) or actively confusing (shared_catalog
     * implies a sync relationship that doesn't exist here).
     */
    public const EXCLUDED_FEATURES = ['shared_stock_pool', 'shared_catalog'];

    /** Shop-level settings copied byte for byte onto the new shop. */
    public const CLONED_SETTINGS = [
        'sales_mode', 'lang', 'vat_mode', 'vat_rate', 'turnover_rate', 'service_charge_rate',
        'hardware_scanner_enabled',
        'memo_settings', 'label_settings',
        'article_barcode_template', 'cost_code_digit_map', 'color_code_map',
        'kids_shoe_size_groups', 'size_group_category_codes',
        'sticker_barcode_template', 'barcode_include_size',
        'default_variant_sizes', 'default_variant_colors',
        'loyalty_earn_rate', 'loyalty_point_value', 'referral_bonus_points',
        'return_window_days', 'void_time_limit_days', 'overhead_allocation_rule', 'dead_stock_days',
        'default_credit_limit',
    ];

    public static function settingsFrom(Shop $source): array
    {
        $attrs = [];
        foreach (self::CLONED_SETTINGS as $key) {
            $attrs[$key] = $source->{$key};
        }

        return $attrs;
    }

    /** @return string[] */
    public static function featureKeysFrom(Shop $source): array
    {
        return $source->features()->pluck('key')
            ->reject(fn ($key) => in_array($key, self::EXCLUDED_FEATURES, true))
            ->values()->all();
    }

    /** @return array{categories:int, units:int, products:int, variants:int} */
    public static function cloneCatalog(Shop $source, Shop $target): array
    {
        $categoryMap = [];
        $categoryCount = 0;
        foreach (ProductCategory::withoutGlobalScopes()->where('shop_id', $source->id)->get() as $cat) {
            $new = ProductCategory::create([
                'shop_id' => $target->id,
                'name' => $cat->name, 'name_en' => $cat->name_en, 'emoji' => $cat->emoji,
                'code' => $cat->code, 'serial_start' => $cat->serial_start,
            ]);
            $categoryMap[$cat->id] = $new->id;
            $categoryCount++;
        }

        $unitMap = [];
        $unitCount = 0;
        foreach (Unit::withoutGlobalScopes()->where('shop_id', $source->id)->get() as $unit) {
            $new = Unit::create([
                'shop_id' => $target->id,
                'name' => $unit->name, 'name_en' => $unit->name_en, 'code' => $unit->code,
            ]);
            $unitMap[$unit->id] = $new->id;
            $unitCount++;
        }

        $productMap = [];
        $productCount = 0;
        foreach (Product::withoutGlobalScopes()->whereNull('deleted_at')->where('shop_id', $source->id)->get() as $p) {
            $new = Product::create([
                'shop_id' => $target->id,
                'category_id' => $p->category_id ? ($categoryMap[$p->category_id] ?? null) : null,
                'unit_id' => $p->unit_id ? ($unitMap[$p->unit_id] ?? null) : null,
                'name' => $p->name, 'name_en' => $p->name_en, 'generic_name' => $p->generic_name,
                'company' => $p->company, 'shelf_location' => $p->shelf_location,
                'requires_prescription' => $p->requires_prescription ?? false, 'emoji' => $p->emoji ?: '📦',
                'photo_path' => $p->photo_path, 'barcode' => $p->barcode,
                'sold_by_weight' => $p->sold_by_weight ?? false, 'weight_unit' => $p->weight_unit,
                'cost' => $p->cost, 'price' => $p->price, 'wholesale_price' => $p->wholesale_price,
                'wholesale_moq' => $p->wholesale_moq, 'discount_price' => $p->discount_price,
                'reorder_point' => $p->reorder_point,
                'stock' => 0,
            ]);
            $productMap[$p->id] = $new->id;
            $productCount++;
        }

        $variantCount = 0;
        foreach (ProductVariant::withoutGlobalScopes()->whereNull('deleted_at')->where('shop_id', $source->id)->get() as $v) {
            $targetProductId = $productMap[$v->product_id] ?? null;
            if (! $targetProductId) {
                continue;
            }
            ProductVariant::create([
                'shop_id' => $target->id, 'product_id' => $targetProductId,
                'size' => $v->size, 'color' => $v->color, 'barcode' => $v->barcode,
                'price' => $v->price, 'cost' => $v->cost, 'photo_path' => $v->photo_path,
                'reorder_point' => $v->reorder_point,
                'stock' => 0,
            ]);
            $variantCount++;
        }

        return ['categories' => $categoryCount, 'units' => $unitCount, 'products' => $productCount, 'variants' => $variantCount];
    }

    /**
     * After a bulk clone, every category's article-series counter must be
     * bumped past whatever serials the clone just created — otherwise the
     * FIRST real article typed into the new shop (no explicit serial,
     * relying on the atomic counter) could be handed a number that already
     * names a just-cloned product, silently creating two live articles with
     * the same name (exactly the B-20007-style duplicate already found in
     * Baby Park's own live data). Bounded to the same ten-thousands block as
     * each category's own band, same reasoning as articles:set-serial-start
     * — an unrelated far-away serial under the same code must never move
     * this band's floor.
     */
    public static function bumpAllCounters(Shop $target): void
    {
        foreach (ProductCategory::withoutGlobalScopes()->where('shop_id', $target->id)->get() as $category) {
            $code = strtoupper(trim((string) $category->code));
            if ($code === '') {
                continue;
            }

            $start = $category->serial_start;
            $blockEnd = $start !== null ? $start + 9999 : null;
            $inBand = array_filter(
                ArticleSerial::serialsUsed($target, $code)['live'],
                fn ($s) => $start === null || ($s >= $start && ($blockEnd === null || $s <= $blockEnd))
            );

            if ($inBand !== []) {
                ArticleSerial::bumpFloor($target, $code, max($inBand) + 1, $start);
            }
        }
    }
}
