<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth for "which shop is the current request acting as".
 *
 * Resolution order:
 *  1. An explicit override bound into the container (used by admin
 *     read-only impersonation views and tests) via `app()->instance('currentShopId', $id)`.
 *  2. A branch the owner has switched to this session (see BranchController)
 *     — only honored if it's actually a sibling of the user's own shop.
 *  3. The logged-in shop user's own shop_id (web session or Sanctum token —
 *     both authenticate against the same `users` table/provider).
 *
 * Admin-guard requests never resolve a shop here, so admin queries are
 * never accidentally tenant-scoped.
 */
class Tenancy
{
    /**
     * Per-request Shop lookup cache — see docs/SCALING_GUIDE.md. Confirmed
     * via audit: HandleInertiaRequests::share() alone calls static::shop()
     * twice every single request (the 'shop' and 'features' props), and
     * EnsureFeatureEnabled calls it again for every feature:-gated route
     * segment (31 across routes/web.php) — a request touching several
     * feature-gated sections could re-run this identical, uncached
     * Shop::find() 10+ times before this fix. Safe as a plain static array
     * under this app's current PHP-FPM/shared-hosting deployment (one
     * fresh process per request); would need clearing per-request (e.g. a
     * request-start listener) if this app ever moves to Octane's
     * persistent workers, since static state would then survive across
     * requests. A handful of controllers mutate a shop's balance columns
     * via a direct `Shop::whereKey(...)->increment()/->decrement()` query
     * (bypassing any Eloquent instance, so this cache can't see it) and
     * then `return back()` — a redirect, so the browser's follow-up GET is
     * a new request with a fresh cache; nothing within the SAME request
     * re-reads a stale balance through here. `Tenancy::shop()->update()`
     * (the other mutation pattern, used by SettingController etc.) mutates
     * this exact cached instance in place, so it can never go stale.
     */
    private static array $shopCache = [];

    private static function findShop(int $id): ?Shop
    {
        if (! array_key_exists($id, self::$shopCache)) {
            self::$shopCache[$id] = Shop::withoutGlobalScopes()->find($id);
        }

        return self::$shopCache[$id];
    }

    public static function id(): ?int
    {
        if (app()->bound('currentShopId')) {
            return app('currentShopId');
        }

        $user = Auth::guard('web')->user() ?? Auth::guard('sanctum')->user();
        if (! $user) {
            return null;
        }

        $activeBranchId = session('active_branch_id');
        if ($activeBranchId && $activeBranchId !== $user->shop_id && static::sameBusiness($user->shop_id, $activeBranchId)) {
            return $activeBranchId;
        }

        return $user->shop_id;
    }

    /**
     * Two shop ids are in the same business if they share a business-root (a
     * main shop's own id, or its parent_shop_id). Public — used both
     * internally (id()'s branch-switch check) and by anything that needs to
     * authorize across a business's branches rather than one exact shop
     * (e.g. CustomerPolicy, now that Customer rows are shared business-wide —
     * see Customer::class and BusinessScope).
     */
    public static function sameBusiness(int $shopId, int $otherId): bool
    {
        $shop = static::findShop($shopId);
        $other = static::findShop($otherId);
        if (! $shop || ! $other) {
            return false;
        }

        $root = $shop->parent_shop_id ?? $shop->id;
        $otherRoot = $other->parent_shop_id ?? $other->id;

        return $root === $otherRoot;
    }

    /** Every shop_id sharing the given shop's business root (itself, its siblings, and its parent) — the set BusinessScope filters a shared model (currently just Customer) to. */
    public static function businessShopIds(?int $shopId = null): array
    {
        $shopId ??= static::id();
        if (! $shopId) {
            return [];
        }

        $shop = static::findShop($shopId);
        if (! $shop) {
            return [$shopId];
        }

        $rootId = $shop->parent_shop_id ?? $shop->id;

        return Shop::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_shop_id', $rootId))
            ->pluck('id')->all();
    }

    public static function shop(): ?Shop
    {
        $id = static::id();

        return $id ? static::findShop($id) : null;
    }

    /** Explicitly set the tenant for the remainder of this request (admin impersonation, artisan commands, tests). */
    public static function set(?int $shopId): void
    {
        app()->instance('currentShopId', $shopId);
    }

    public static function clear(): void
    {
        if (app()->bound('currentShopId')) {
            app()->forgetInstance('currentShopId');
        }
    }

    /** Drops the per-request Shop cache — tests that fetch/mutate a shop by a fresh query and then expect static::shop() to see it need this; a real request never needs to call it. */
    public static function forgetShopCache(): void
    {
        self::$shopCache = [];
    }
}
