<?php

namespace App\Models\Concerns;

use App\Support\Tenancy;

/**
 * Lighter sibling of BelongsToTenant: stamps shop_id on create but does NOT
 * add a global read scope. Used only by the `User` model — login must be
 * able to look a user up by phone/email *before* a tenant is known, so a
 * blanket "scope to current shop" would make login impossible. Any
 * shop-scoped listing of users must filter explicitly (`where('shop_id', ...)`).
 */
trait StampsTenantOnCreate
{
    public static function bootStampsTenantOnCreate(): void
    {
        static::creating(function ($model) {
            if (empty($model->shop_id)) {
                $model->shop_id = Tenancy::id();
            }
        });
    }
}
