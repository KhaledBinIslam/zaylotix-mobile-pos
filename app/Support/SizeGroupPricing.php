<?php

namespace App\Support;

use App\Models\ProductCategory;
use App\Models\Shop;

/**
 * docs/ARTICLE_SERIES_PLAN.md §4 — "বাচ্চাদের জুতা" (Kids' Shoes) pricing:
 * one article, three price tiers (Small/Medium/Large) by size, resolved
 * against the shop's own (owner-configurable) size-group boundaries
 * (Shop::kidsShoeSizeGroups()). Pure string/array logic, no DB access, so
 * it's trivially unit-testable like App\Support\ArticleBarcode.
 *
 * This does NOT write to ProductVariant on its own — the caller (the
 * one-screen entry form / import command, not yet built) resolves each
 * variant's price via priceForSize() and assigns it to the variant's own
 * `price` column, which ProductVariant::effectivePrice() already prefers
 * over the article's base price. No change needed there.
 */
class SizeGroupPricing
{
    /** Category names (English/Bengali) that trigger group pricing when the shop has picked no categories of its own — exactly the names config/business_types.php seeds, same synonym source ProductsImportArticles::CATEGORY_SYNONYMS already uses for 'KIDS'. */
    public const KIDS_CATEGORY_NAMES = ["Kids' Shoes", 'বাচ্চাদের জুতা'];

    /**
     * Does this category price by size group?
     *
     * The shop's own picks win where it has made any (matched by category
     * CODE — a branch gets its own ProductCategory rows with fresh ids, so
     * ids are not a cross-branch key; see CatalogSync). The seeded names
     * are only a fallback for a shop that has never opened the settings.
     *
     * Shared so that every screen which can create a variant reaches the
     * SAME verdict as the article form — the stock grid used to reach no
     * verdict at all and priced a size-16 pair at the article's headline
     * price.
     */
    public static function appliesTo(Shop $shop, ?ProductCategory $category): bool
    {
        if ($category === null) {
            return false;
        }

        $codes = $shop->sizeGroupCategoryCodes();
        if ($codes !== []) {
            return in_array(strtoupper(trim((string) $category->code)), $codes, true);
        }

        return in_array(trim((string) $category->name_en), self::KIDS_CATEGORY_NAMES, true)
            || in_array(trim((string) $category->name), self::KIDS_CATEGORY_NAMES, true);
    }

    /** The configured group whose [min_size, max_size] contains this size, or null if the size has no numeric part or falls in a gap between groups. */
    public static function groupForSize(Shop $shop, string $size): ?array
    {
        $numericSize = self::extractNumericSize($size);
        if ($numericSize === null) {
            return null;
        }

        foreach ($shop->kidsShoeSizeGroups() as $group) {
            if ($numericSize >= $group['min_size'] && $numericSize <= $group['max_size']) {
                return $group;
            }
        }

        return null;
    }

    /** $groupPrices is label -> price, e.g. ['Small' => 500, 'Medium' => 550, 'Large' => 600] -- the owner's 3 entered prices for this article. Null if the size doesn't resolve to a configured group, or that group has no price entered. */
    public static function priceForSize(Shop $shop, string $size, array $groupPrices): ?float
    {
        $group = self::groupForSize($shop, $size);
        if ($group === null || ! isset($groupPrices[$group['label']])) {
            return null;
        }

        return (float) $groupPrices[$group['label']];
    }

    private static function extractNumericSize(string $size): ?int
    {
        return preg_match('/\d+/', $size, $m) ? (int) $m[0] : null;
    }
}
