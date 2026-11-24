<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\Tenancy;

/**
 * Second line of defense behind the TenantScope global scope: even if a
 * model somehow reaches a controller via a route-model-binding that bypassed
 * scoping, the policy still refuses to let a shop user touch a row that
 * isn't their own.
 */
trait ChecksTenantOwnership
{
    /**
     * The row belongs to the user's own shop OR to the branch they are
     * working in right now. An owner who switched into a branch
     * (BranchController::switch) is shown that branch's rows by every
     * tenant-scoped query, but their User.shop_id is still their HOME
     * shop — comparing only that made every view/delete on a branch's own
     * rows a 403 (Baby Park: held carts listed but "অনুমতি নেই" on delete,
     * 2026-11-24). Tenancy::id() already refuses a branch outside the
     * user's own business, so this widens nothing across businesses.
     */
    private function ownsRow(User $user, $model): bool
    {
        return (int) $user->shop_id === (int) $model->shop_id
            || (int) Tenancy::id() === (int) $model->shop_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, $model): bool
    {
        return $this->ownsRow($user, $model);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, $model): bool
    {
        return $this->ownsRow($user, $model);
    }

    public function delete(User $user, $model): bool
    {
        return $this->ownsRow($user, $model);
    }

    public function restore(User $user, $model): bool
    {
        return $this->ownsRow($user, $model);
    }

    public function forceDelete(User $user, $model): bool
    {
        return $this->ownsRow($user, $model);
    }
}
