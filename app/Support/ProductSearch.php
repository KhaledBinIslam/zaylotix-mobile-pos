<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One shared search implementation for every server-side product picker
 * (docs/FIX_PLAN.md's ধাপ ২.জ — this app previously had 5 independent,
 * unnormalized `LIKE` implementations; see ধাপ ১'s root-cause note).
 * Reused by ProductController::search(), PosController::search(); the
 * paginated Stock-page list (ProductController::index()) applies only the
 * literal normalized filter, not the fuzzy fallback below — a stock LIST
 * browsing search tolerates a "no results," a cashier mid-sale shouldn't.
 *
 * Ranking (ধাপ ২.খ/ঙ): literal tiers (barcode/prefix/word-prefix/
 * substring) first; if literally nothing matches, a SECOND pass widens to
 * a capped candidate set and ranks by SearchMatcher's typo/phonetic tiers
 * instead — this is the actual "did you mean" fallback, not a separate
 * UI-only feature. Within an equal tier: in-stock before out-of-stock,
 * then most-recently-added first.
 */
class ProductSearch
{
    /**
     * `$query` must already carry every other filter (shop scope via the
     * model's own global scope, category, relations, …) but must NOT have
     * `->limit()`/`->get()` called yet — this method owns both.
     */
    public static function run(Builder $query, string $rawQuery, int $limit, int $fallbackCandidateCap = 500): Collection
    {
        $normalized = SearchNormalizer::normalize($rawQuery);
        if ($normalized === '') {
            return (clone $query)->limit($limit)->get();
        }

        $literalMatches = (clone $query)
            ->where(fn ($w) => $w->where('search_text', 'like', '%'.$normalized.'%')->orWhere('barcode', $rawQuery))
            ->limit($fallbackCandidateCap)
            ->get();

        $ranked = self::rank($literalMatches, $normalized, $rawQuery);
        if ($ranked->isNotEmpty()) {
            return $ranked->take($limit)->values();
        }

        // Literal pass found nothing at all — widen to a capped candidate
        // set and lean entirely on SearchMatcher's typo/phonetic tiers.
        // Capped regardless of catalog size so one fuzzy fallback query
        // never scans an entire 10,000-SKU catalog.
        $candidates = (clone $query)->limit($fallbackCandidateCap)->get();

        return self::rank($candidates, $normalized, $rawQuery)->take($limit)->values();
    }

    private static function rank(Collection $products, string $normalized, string $rawQuery): Collection
    {
        return $products
            ->map(fn ($p) => [
                'product' => $p,
                'tier' => SearchMatcher::bestTier($normalized, [$p->search_text ?? $p->buildSearchText()], $rawQuery !== '' && $p->barcode === $rawQuery),
            ])
            ->filter(fn ($row) => $row['tier'] !== null)
            ->sortBy(fn ($row) => sprintf('%02d-%d-%010d', $row['tier'], self::outOfStockRank($row['product']), 9999999999 - $row['product']->id))
            ->pluck('product')
            ->values();
    }

    private static function outOfStockRank(Product $p): int
    {
        return $p->isStockTracked() && (float) $p->stock <= 0 ? 1 : 0;
    }
}
