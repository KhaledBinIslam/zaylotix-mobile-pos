<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;

/**
 * Everything a variant of an ARTICLE needs that a plain size/colour
 * variant does not: its own scannable barcode, with that size's own
 * buying price hidden inside it.
 *
 * ArticleController::store() builds all of this while creating the
 * article, from parts it has in hand. A size added later — from the stock
 * screen's grid, or one at a time — went in with no barcode and no price
 * at all, so it could not be scanned, could not be stickered, and sold at
 * the article's headline price whatever group it belonged to. Khaled hit
 * exactly that: "oi serial e gia product add korle purchase r sell price
 * input diar option nai plus etar barcode o create hocche na".
 *
 * Only a product that still looks like an article is touched. A grocery
 * item carrying a supplier's EAN is left exactly as it is — minting
 * "5901234-RED-M" off the back of it would invent a barcode nothing ever
 * printed.
 */
class ArticleVariant
{
    /**
     * An article is named "{CAT}-{SERIAL}" by ArticleController::store()
     * and carries a barcode built from the shop's own template. Both
     * together are the test: a name alone could be a coincidence, and a
     * barcode alone is every other product in the shop.
     */
    public static function isArticle(?Product $product): bool
    {
        return $product !== null
            && $product->barcode !== null
            && $product->barcode !== ''
            && self::serialOf($product) !== null;
    }

    /** The serial out of an article's name, or null when the name isn't "{its category's code}-{digits}". */
    public static function serialOf(Product $product): ?int
    {
        $code = strtoupper(trim((string) $product->category?->code));
        if ($code === '') {
            return null;
        }

        return preg_match('/^'.preg_quote($code, '/').'-(\d+)$/i', trim((string) $product->name), $m)
            ? (int) $m[1]
            : null;
    }

    /**
     * The barcode a new variant of this article should carry, or null
     * when the product is not an article (leave it alone then — that is
     * today's behaviour for every other kind of product).
     *
     * When the variant buys at its own price, the article segment is
     * re-stamped with that price's cost code, so the sticker on a size-16
     * pair hides what a size-16 pair actually cost — the same thing
     * ArticleController::store() does for the sizes entered up front.
     */
    public static function barcodeFor(Shop $shop, Product $product, ?string $color, ?string $size, float|int|string|null $cost = null): ?string
    {
        if (! self::isArticle($product)) {
            return null;
        }

        $articleBarcode = (string) $product->barcode;
        if ($cost !== null && $cost !== '') {
            $articleBarcode = self::restampCost($shop, $product, (int) $cost) ?? $articleBarcode;
        }

        $barcode = ArticleBarcode::buildVariantBarcode($shop, $articleBarcode, $color, (string) ($size ?? ''));

        // A colour with no configured short code falls back to its own
        // name, so a Bengali colour builds a barcode no scanner can read
        // and JsBarcode cannot draw. No barcode at all beats one that
        // cannot be printed — and the owner has somewhere to fix it,
        // which guessing a code here would take away from them.
        return ArticleBarcode::isPrintable($barcode) ? $barcode : null;
    }

    /**
     * The article's barcode with a different cost hidden in it.
     *
     * Rebuilt from the product's own parts (supplier short code, category
     * code, serial) rather than by cutting the stored string up, because
     * the barcode template is the shop's to change. That only holds while
     * those parts still produce the barcode the article actually carries,
     * so that is checked first against the article's OWN cost: if a
     * supplier was renamed, or the template edited since, this returns
     * null and the caller keeps the article's real barcode instead of
     * inventing a near-miss.
     */
    public static function restampCost(Shop $shop, Product $product, int $cost): ?string
    {
        $categoryCode = strtoupper(trim((string) $product->category?->code));
        $mfr = strtoupper(trim((string) $product->supplier?->short_code));
        $serial = self::serialOf($product);
        if ($categoryCode === '' || $mfr === '' || $serial === null || $product->cost === null) {
            return null;
        }

        $ownCode = ArticleBarcode::encodeCost($shop, (int) $product->cost);
        if ($ownCode === null) {
            return null;
        }
        if (ArticleBarcode::buildArticleBarcode($shop, $mfr, $categoryCode, $serial, $ownCode) !== $product->barcode) {
            return null;
        }

        $code = ArticleBarcode::encodeCost($shop, $cost);
        if ($code === null) {
            return null;
        }

        return ArticleBarcode::buildArticleBarcode($shop, $mfr, $categoryCode, $serial, $code);
    }

    /**
     * The same barcode, but only if nothing else in the shop already
     * holds it — soft-deleted variants included, since those still
     * occupy the unique (shop_id, barcode) index. A collision means this
     * exact size/colour existed before and was removed; the variant is
     * still created, just without a barcode, rather than failing the
     * whole submit with a database error.
     */
    public static function freeBarcodeFor(Shop $shop, Product $product, ?string $color, ?string $size, float|int|string|null $cost = null): ?string
    {
        $barcode = self::barcodeFor($shop, $product, $color, $size, $cost);
        if ($barcode === null) {
            return null;
        }

        $taken = ProductVariant::withoutGlobalScopes()
            ->where('shop_id', $product->shop_id)
            ->where('barcode', $barcode)
            ->exists();

        return $taken ? null : $barcode;
    }
}
