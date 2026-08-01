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
 *  2. The logged-in shop user's own shop_id (web session or Sanctum token —
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

        return $user?->shop_id;
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
