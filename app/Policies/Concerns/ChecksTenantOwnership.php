<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Second line of defense behind the TenantScope global scope: even if a
 * model somehow reaches a controller via a route-model-binding that bypassed
 * scoping, the policy still refuses to let a shop user touch a row that
 * isn't their own.
 */
trait ChecksTenantOwnership
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, $model): bool
    {
        return $user->shop_id === $model->shop_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, $model): bool
    {
        return $user->shop_id === $model->shop_id;
    }

    public function delete(User $user, $model): bool
    {
        return $user->shop_id === $model->shop_id;
    }

    public function restore(User $user, $model): bool
    {
        return $user->shop_id === $model->shop_id;
    }

    public function forceDelete(User $user, $model): bool
    {
        return $user->shop_id === $model->shop_id;
    }
}
