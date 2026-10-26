<?php

namespace App\Support;

use App\Models\ArticleSeriesCounter;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * docs/ARTICLE_SERIES_PLAN.md §2 — one serial counter per category code,
 * shared across a main shop and every branch (confirmed by reading
 * CatalogSync: ProductCategory rows aren't linked across shops today, so
 * the counter can't safely live on that model — see
 * ArticleSeriesCounter's own docblock).
 */
class ArticleSerial
{
    private const START = 10001;

    /**
     * Race-safe even for the very first article of a brand-new category
     * code: `insertOrIgnore` is atomic at the DB level (a concurrent
     * duplicate insert is silently dropped, never errors), so the
     * following `lockForUpdate()` select always finds exactly one row to
     * lock and increment, regardless of how many requests raced to create
     * it.
     */
    public static function next(Shop $shop, string $categoryCode): int
    {
        $mainShopId = $shop->parent_shop_id ?? $shop->id;

        return DB::transaction(function () use ($mainShopId, $categoryCode) {
            ArticleSeriesCounter::query()->insertOrIgnore([
                'main_shop_id' => $mainShopId,
                'category_code' => $categoryCode,
                'next_serial' => self::START,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = ArticleSeriesCounter::where('main_shop_id', $mainShopId)
                ->where('category_code', $categoryCode)
                ->lockForUpdate()
                ->first();

            $serial = $counter->next_serial;
            $counter->increment('next_serial');

            return $serial;
        });
    }

    /**
     * Used by the Excel importer: after importing articles with known,
     * already-used serials (e.g. 10001-10010), the counter must advance
     * PAST the highest one actually used, or the next live-created
     * article would collide. Never moves the counter backward — a no-op
     * if it's already past `atLeast`.
     */
    public static function bumpFloor(Shop $shop, string $categoryCode, int $atLeast): void
    {
        $mainShopId = $shop->parent_shop_id ?? $shop->id;

        DB::transaction(function () use ($mainShopId, $categoryCode, $atLeast) {
            ArticleSeriesCounter::query()->insertOrIgnore([
                'main_shop_id' => $mainShopId,
                'category_code' => $categoryCode,
                'next_serial' => self::START,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ArticleSeriesCounter::where('main_shop_id', $mainShopId)
                ->where('category_code', $categoryCode)
                ->where('next_serial', '<', $atLeast)
                ->update(['next_serial' => $atLeast]);
        });
    }

    /** Read-only peek at what the next serial WOULD be — for the one-screen entry form's live preview (not built yet). Never reserves it. */
    public static function peekNext(Shop $shop, string $categoryCode): int
    {
        $mainShopId = $shop->parent_shop_id ?? $shop->id;

        return ArticleSeriesCounter::where('main_shop_id', $mainShopId)
            ->where('category_code', $categoryCode)
            ->value('next_serial') ?? self::START;
    }
}
