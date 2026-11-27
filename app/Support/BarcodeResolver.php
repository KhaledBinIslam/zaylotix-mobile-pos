<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Models\ProductSource;
use App\Models\ProductVariant;

/**
 * The single, shared "what does this scanned string actually mean, in this
 * shop's own catalog" resolver — docs/ARTICLE_SERIES_PLAN.md §9/§10's full
 * tier1 (variant barcode) / tier2 (color barcode + alias) / tier3 (plain
 * product barcode) / ZL (lot) / ZV (variant id) / ZC (color-barcode id)
 * scheme, plus the ZV/ZC cross-branch fallback (Baby Park live bug, "Product
 * not found: ZV1288" — a label's short code encodes the id of whichever
 * branch's own row was showing when it was printed, which a sibling branch
 * doesn't share; see that fallback's own comment below for the full story).
 *
 * Extracted from PosController::barcode() (2026-11-15) because
 * ReturnController::barcodeLookup() — the exchange flow's own "scan the new
 * item" picker — had quietly never had ANY of this: not the three tiers,
 * not ZV/ZC, not the cross-branch fallback, just a bare exact-string match
 * on Product/ProductVariant.barcode. Every scan consumer must call this one
 * resolver from now on so the three never drift apart again.
 */
class BarcodeResolver
{
    /** @return array{found: bool, product?: Product, variant_id?: int, color?: string, source_id?: int} */
    /**
     * @param  bool  $shortCodes  false = treat $barcode as a plain stored barcode string even if it LOOKS like a
     *                            ZL/ZV/ZC short code. The sibling-branch fallbacks pass false: they are looking up a
     *                            barcode string that was stored on a row, and a plain product's stored barcode IS a
     *                            'ZV{id}' string — re-reading it as a short code sent the lookup round and round
     *                            until PHP's stack overflowed (Liberty owner, 2026-11-27: scanning a main-shop
     *                            sticker at the branch answered HTTP 500 "Maximum call stack size reached").
     */
    public static function resolve(string $barcode, bool $shortCodes = true): array
    {
        if ($shortCodes && preg_match('/^ZL(\d+)$/', $barcode, $m)) {
            return self::resolveLot((int) $m[1]);
        }
        if ($shortCodes && preg_match('/^ZV(\d+)$/', $barcode, $m)) {
            return self::resolveVariantId((int) $m[1]);
        }
        if ($shortCodes && preg_match('/^ZC(\d+)$/', $barcode, $m)) {
            return self::resolveColorBarcodeId((int) $m[1]);
        }

        // tier 1 — a variant's own barcode. $variant->product can be null:
        // Product soft-deletes now cascade to their variants, but a product
        // removed BEFORE that cascade existed left its variants live, and a
        // sticker printed for one of those still scans. Treating it as
        // not-found (falling through to the tiers below) is what a deleted
        // product should do anyway.
        $variant = ProductVariant::with('product.productUnits.unit')->where('barcode', $barcode)->first();
        if ($variant && $variant->product) {
            $product = $variant->product;
            $product->setRelation('variants', $product->variants()->get());

            return ['found' => true, 'product' => $product, 'variant_id' => $variant->id];
        }

        // tier 2 — the color-level sticker barcode (and its history of old
        // values, once a color's code is renamed) resolves to a product +
        // which color, not one exact variant.
        $colorBarcode = ProductColorBarcode::where('barcode', $barcode)->first()
            ?? ProductColorBarcodeAlias::where('barcode', $barcode)->first();
        if ($colorBarcode) {
            $product = Product::with(['productUnits.unit', 'variants'])->find($colorBarcode->product_id);
            if ($product) {
                return ['found' => true, 'product' => $product, 'color' => $colorBarcode->color];
            }
        }

        // tier 3 — the plain product-level barcode
        $product = Product::with(['productUnits.unit', 'variants'])->where('barcode', $barcode)->first();
        if ($product) {
            return ['found' => true, 'product' => $product];
        }

        return ['found' => false];
    }

    private static function resolveLot(int $sourceId): array
    {
        $source = ProductSource::with('product.productUnits.unit')->find($sourceId);
        if ($source && $source->product) {
            $product = $source->product;
            $product->setRelation('variants', $product->variants()->get());

            return ['found' => true, 'product' => $product, 'variant_id' => $source->product_variant_id, 'source_id' => $source->id];
        }

        return ['found' => false];
    }

    private static function resolveVariantId(int $variantId): array
    {
        $variant = ProductVariant::with('product.productUnits.unit')->find($variantId);
        if ($variant) {
            $product = $variant->product;
            $product->setRelation('variants', $product->variants()->get());

            return ['found' => true, 'product' => $product, 'variant_id' => $variant->id];
        }

        // Baby Park live bug (shop 81, "Product not found: ZV1288"): the id
        // is a single global auto-increment column, so a label printed
        // while viewing one branch encodes THAT branch's own variant row —
        // not found under a sibling branch's tenant scope even though the
        // exact same product/size/color exists there too. Cross-resolve via
        // the sibling's own .barcode string (identical on both copies by
        // CatalogSync), then re-run the ordinary tenant-scoped resolution
        // for THIS shop.
        $sibling = ProductVariant::withoutGlobalScopes()->find($variantId);
        if ($sibling && $sibling->barcode && Tenancy::id() && Tenancy::sameBusiness(Tenancy::id(), $sibling->shop_id)) {
            return self::resolve($sibling->barcode, false);
        }

        return ['found' => false];
    }

    private static function resolveColorBarcodeId(int $colorBarcodeId): array
    {
        $colorBarcode = ProductColorBarcode::find($colorBarcodeId);
        if ($colorBarcode) {
            $product = Product::with(['productUnits.unit', 'variants'])->find($colorBarcode->product_id);
            if ($product) {
                return ['found' => true, 'product' => $product, 'color' => $colorBarcode->color];
            }
        }

        // same cross-branch id fallback as resolveVariantId() above
        $sibling = ProductColorBarcode::withoutGlobalScopes()->find($colorBarcodeId);
        if ($sibling && $sibling->barcode && Tenancy::id() && Tenancy::sameBusiness(Tenancy::id(), $sibling->shop_id)) {
            return self::resolve($sibling->barcode, false);
        }

        return ['found' => false];
    }
}
