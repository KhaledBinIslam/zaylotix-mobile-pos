<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

/**
 * Shops themselves aren't tenant-scoped by TenantScope (a shop can't belong
 * to itself). A logged-in shop user may only view/update their own shop
 * record; all admin-guard access is short-circuited by Gate::before in
 * AppServiceProvider before this policy ever runs.
 */
class ShopPolicy
{
    public function view(User $user, Shop $shop): bool
    {
        return $user->shop_id === $shop->id;
    }

    public function update(User $user, Shop $shop): bool
    {
        return $user->shop_id === $shop->id;
    }
}
