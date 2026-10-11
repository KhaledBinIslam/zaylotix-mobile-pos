<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantOwnership;
use App\Support\Tenancy;

class CustomerPolicy
{
    use ChecksTenantOwnership;

    /**
     * Customer rows are shared business-wide (see Customer::class /
     * BusinessScope) — the `shop_id` stamped on one is just wherever they
     * were first seen, which may be a DIFFERENT branch than whoever's
     * collecting a payment from or editing them right now. Overrides the
     * trait's exact-shop_id check with a same-business check instead, same
     * reasoning Tenancy::id() already uses for a branch-switched session.
     */
    public function view(User $user, Customer $customer): bool
    {
        return Tenancy::sameBusiness($user->shop_id, $customer->shop_id);
    }

    public function update(User $user, Customer $customer): bool
    {
        return Tenancy::sameBusiness($user->shop_id, $customer->shop_id);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return Tenancy::sameBusiness($user->shop_id, $customer->shop_id);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return Tenancy::sameBusiness($user->shop_id, $customer->shop_id);
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return Tenancy::sameBusiness($user->shop_id, $customer->shop_id);
    }
}
