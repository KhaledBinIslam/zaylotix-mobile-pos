<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Models\Shop;

/**
 * docs/ARTICLE_SERIES_PLAN.md §9 — the one place a ProductColorBarcode row
 * is ever written (new article creation, products:import-articles,
 * articles:regenerate-barcodes), so "move the old value to aliases before
 * overwriting" can never be forgotten in one call site but not another.
 */
class ColorBarcodeResolver
{
    /**
     * Ensures (product, color)'s ProductColorBarcode row reflects the
     * CURRENT computed value, aliasing the old one first if it's actually
     * changing. A no-op (no new alias, no write) when nothing changed —
     * safe to call unconditionally on every article save, not just when
     * something is known to be different.
     */
    public static function resolve(Shop $shop, Product $product, string $color, string $articleBarcode): ProductColorBarcode
    {
        $computed = ArticleBarcode::buildColorBarcode($shop, $articleBarcode, $color);

        // explicit withoutGlobalScopes()+shop_id, not the tenant-scoped default —
        // this is called from console commands (products:import-articles,
        // articles:regenerate-barcodes) where Tenancy::id() is never set, same
        // reasoning as ArticleSerial/CatalogSync; must work identically from an
        // HTTP request (ArticleController) where it IS set
        $existing = ProductColorBarcode::withoutGlobalScopes()
            ->where('shop_id', $shop->id)->where('product_id', $product->id)->where('color', $color)->first();

        if (! $existing) {
            return ProductColorBarcode::create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'color' => $color, 'barcode' => $computed,
            ]);
        }

        if ($existing->barcode !== $computed) {
            ProductColorBarcodeAlias::create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'color' => $color,
                'barcode' => $existing->barcode, 'created_at' => now(),
            ]);
            $existing->update(['barcode' => $computed]);
        }

        return $existing;
    }
}
