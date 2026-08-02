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

    /** Two shop ids are in the same business if they share a business-root (a main shop's own id, or its parent_shop_id). */
    private static function sameBusiness(int $shopId, int $otherId): bool
    {
        $shop = Shop::withoutGlobalScopes()->find($shopId);
        $other = Shop::withoutGlobalScopes()->find($otherId);
        if (! $shop || ! $other) {
            return false;
        }

        $root = $shop->parent_shop_id ?? $shop->id;
        $otherRoot = $other->parent_shop_id ?? $other->id;

        return $root === $otherRoot;
    }

    public static function shop(): ?Shop
    {
        $id = static::id();

        return $id ? Shop::withoutGlobalScopes()->find($id) : null;
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
}
