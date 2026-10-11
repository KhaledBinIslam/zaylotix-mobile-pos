<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Like TenantScope, but scopes to every shop sharing the current one's
 * business root (the main shop and every one of its branches) instead of
 * the exact active shop_id — for a model meant to be shared across a
 * business's branches rather than kept separate per branch. Currently only
 * Customer: a customer's due/loyalty/visit history follows the person, not
 * which branch's till they happened to be standing at (Khaled's explicit
 * "কাস্টমার পুরো ব্র্যান্ডে শেয়ার করা" ask). For a shop with no branches at
 * all, businessShopIds() returns just that one shop — identical behavior to
 * TenantScope, so this is a no-op for every shop that isn't multi-branch.
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $shopId = Tenancy::id();

        if (! $shopId) {
            // same "scope to an impossible id" fallback as TenantScope —
            // never silently return every business's customers
            $builder->where($model->qualifyColumn('shop_id'), 0);

            return;
        }

        $builder->whereIn($model->qualifyColumn('shop_id'), Tenancy::businessShopIds($shopId));
    }
}
