<?php

namespace App\Support;

use App\Models\ArticleSeriesCounter;
use App\Models\Product;
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

    /**
     * What to prefill a new article's serial with: the lowest number this
     * category has NOT got a live article for.
     *
     * The counter alone only ever moves forward, so deleting B-20007 and
     * B-20008 left the next article starting at 20009 with two numbers
     * stranded. Khaled asked for those to come back. A gap only exists
     * where an article was deleted, and the serial field is editable
     * anyway, so this is a suggestion rather than a rule —
     * ArticleController::store()'s own check is what guarantees two live
     * articles never share one.
     *
     * The walk starts at the lowest serial this category has EVER used,
     * deleted articles included — not at self::START, since imported
     * categories began at 20001 and starting from 10001 would suggest a
     * number from a range this shop has never used.
     *
     * It used to start at the lowest LIVE one, which made a gap below it
     * invisible: Khaled deleted B-20007 and B-20008, the lowest live
     * article became B-20009, and the walk could then only ever look
     * upwards from there.
     *
     * Lives here rather than on the controller so the doctor command can
     * print the same answer the form will show, without a web request.
     */
    public static function nextFree(Shop $shop, string $categoryCode): int
    {
        $counterNext = self::peekNext($shop, $categoryCode);
        $serials = self::serialsUsed($shop, $categoryCode);

        if ($serials['everLowest'] === null) {
            return $counterNext;
        }

        $taken = array_flip($serials['live']);
        for ($candidate = $serials['everLowest']; $candidate < $counterNext; $candidate++) {
            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return $counterNext;
    }

    /**
     * Every serial this category has handed out, split into the ones a
     * live article still answers to and the ones a deleted article left
     * behind. Business-wide, because the counter itself is shared by a
     * shop and its branches.
     *
     * withoutGlobalScopes() throughout: this is called from a console
     * command too, where TenantScope resolves to shop_id = 0 and would
     * match nothing.
     *
     * @return array{live: int[], freed: int[], everLowest: ?int}
     */
    public static function serialsUsed(Shop $shop, string $categoryCode): array
    {
        $shopIds = Tenancy::businessShopIds($shop->id);

        $serialsOf = fn ($query) => $query
            ->whereIn('shop_id', $shopIds)
            ->where('name', 'like', $categoryCode.'-%')
            ->pluck('name')
            ->map(fn ($name) => (int) substr((string) $name, strlen($categoryCode) + 1))
            ->filter(fn ($serial) => $serial > 0)
            ->unique()
            ->sort()
            ->values();

        // only a LIVE article blocks a number; a deleted one is exactly
        // what frees it (ArticleController::store()'s own check agrees)
        $live = $serialsOf(Product::withoutGlobalScopes()->whereNull('deleted_at'));
        $ever = $serialsOf(Product::withoutGlobalScopes());

        return [
            'live' => $live->all(),
            'freed' => $ever->diff($live)->values()->all(),
            'everLowest' => $ever->min(),
        ];
    }
}
