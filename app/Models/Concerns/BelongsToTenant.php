<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\Tenancy;

/**
 * Apply to every tenant-owned model. Adds a global scope that filters all
 * queries to the current shop, and auto-stamps shop_id on create so callers
 * never need to remember `where('shop_id', ...)` themselves.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->shop_id)) {
                $model->shop_id = Tenancy::id();
            }
        });
    }

    public function scopeForShop($query, int $shopId)
    {
        return $query->withoutGlobalScope(TenantScope::class)->where('shop_id', $shopId);
    }
}
