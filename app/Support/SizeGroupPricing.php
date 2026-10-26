<?php

namespace App\Support;

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
