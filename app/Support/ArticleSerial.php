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
 *
 * A category may additionally pin where its numbers START
 * (product_categories.serial_start) — Khaled reads the kind of shoe off
 * the number itself: slippers 90000-up, ladies 10000-up, baby 20000-up,
 * pumpi 50000-up, ladies-cat 30000-up. Every method here takes that band
 * as its last argument; passing null means the historic single band
 * beginning at START, so a category that never set one behaves exactly as
 * it always has.
 *
 * Two categories sharing one code may sit in different bands, so the
 * counter is keyed by (main shop, code, band) — see the band migration.
 */
class ArticleSerial
{
    private const START = 10001;

    /** The band a category's numbers run in — its own, or the historic default. */
    public static function bandOf(?int $serialStart): int
    {
        return $serialStart !== null && $serialStart > 0 ? $serialStart : self::START;
    }

    /**
     * Race-safe even for the very first article of a brand-new category
     * code: `insertOrIgnore` is atomic at the DB level (a concurrent
     * duplicate insert is silently dropped, never errors), so the
     * following `lockForUpdate()` select always finds exactly one row to
     * lock and increment, regardless of how many requests raced to create
     * it.
     */
    public static function next(Shop $shop, string $categoryCode, ?int $serialStart = null): int
    {
        $mainShopId = $shop->parent_shop_id ?? $shop->id;
        $band = self::bandOf($serialStart);

        return DB::transaction(function () use ($mainShopId, $categoryCode, $band) {
            ArticleSeriesCounter::query()->insertOrIgnore([
                'main_shop_id' => $mainShopId,
                'category_code' => $categoryCode,
                'series_start' => $band,
                'next_serial' => $band,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = ArticleSeriesCounter::where('main_shop_id', $mainShopId)
                ->where('category_code', $categoryCode)
                ->where('series_start', $band)
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
    public static function bumpFloor(Shop $shop, string $categoryCode, int $atLeast, ?int $serialStart = null): void
    {
        $mainShopId = $shop->parent_shop_id ?? $shop->id;
        $band = self::bandOf($serialStart);

        DB::transaction(function () use ($mainShopId, $categoryCode, $atLeast, $band) {
            ArticleSeriesCounter::query()->insertOrIgnore([
                'main_shop_id' => $mainShopId,
                'category_code' => $categoryCode,
                'series_start' => $band,
                'next_serial' => $band,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            ArticleSeriesCounter::where('main_shop_id', $mainShopId)
                ->where('category_code', $categoryCode)
                ->where('series_start', $band)
                ->where('next_serial', '<', $atLeast)
                ->update(['next_serial' => $atLeast]);
        });
    }

    /** Read-only peek at what the next serial WOULD be — for the one-screen entry form's live preview. Never reserves it. */
    public static function peekNext(Shop $shop, string $categoryCode, ?int $serialStart = null): int
    {
        $mainShopId = $shop->parent_shop_id ?? $shop->id;
        $band = self::bandOf($serialStart);

        return ArticleSeriesCounter::where('main_shop_id', $mainShopId)
            ->where('category_code', $categoryCode)
            ->where('series_start', $band)
            ->value('next_serial') ?? $band;
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
     * With a band set, the walk starts at the band: a Slippers article
     * must be offered 90000-something even while Ladies — the same code,
     * a different band — sits at 10041. Numbers below the band belong to
     * the other category and must never be suggested here.
     *
     * Without one, the walk starts at the lowest serial this category has
     * EVER used, deleted articles included — not at self::START, since
     * imported categories began at 20001 and starting from 10001 would
     * suggest a number from a range this shop has never used. It used to
     * start at the lowest LIVE one, which made a gap below it invisible:
     * Khaled deleted B-20007 and B-20008, the lowest live article became
     * B-20009, and the walk could then only ever look upwards from there.
     *
     * Lives here rather than on the controller so the doctor command can
     * print the same answer the form will show, without a web request.
     */
    public static function nextFree(Shop $shop, string $categoryCode, ?int $serialStart = null): int
    {
        $counterNext = self::peekNext($shop, $categoryCode, $serialStart);
        $serials = self::serialsUsed($shop, $categoryCode);

        $from = $serialStart !== null && $serialStart > 0 ? $serialStart : $serials['everLowest'];
        if ($from === null) {
            return $counterNext;
        }

        $taken = array_flip($serials['live']);
        for ($candidate = $from; $candidate < $counterNext; $candidate++) {
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
     * Deliberately NOT filtered by band: two categories on one code draw
     * from the same pool of names, so a number held by either of them is
     * taken for both. Band-awareness belongs in where the walk STARTS
     * (nextFree), never in what counts as occupied.
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
