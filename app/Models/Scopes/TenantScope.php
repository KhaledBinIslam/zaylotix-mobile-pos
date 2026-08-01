<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $shopId = Tenancy::id();

        // If there is no resolvable tenant (e.g. an admin-guard request, or a
        // console command that hasn't set one explicitly), scope to an
        // impossible id rather than silently returning every shop's rows.
        $builder->where($model->qualifyColumn('shop_id'), $shopId ?? 0);
    }
}
