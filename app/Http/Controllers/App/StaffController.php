<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Owner-managed cashier accounts — any number per shop (the earlier
 * one-cashier cap is lifted; a supershop with several people on the
 * register at once needs each of them to check out sales under their own
 * account for cash-drawer reconciliation to mean anything — see
 * Reports::cashierCashBreakdown()). The owner decides exactly which app
 * sections (config/staff_permissions.php) each cashier can reach; every
 * one of those sections is enforced server-side by the `perm:<key>`
 * middleware on the relevant routes, not just hidden in the nav, the same
 * way shop-level `feature:<key>` gates work for admin grants.
 */
class StaffController extends Controller
{
    /**
     * `User` uses StampsTenantOnCreate (not the full tenant-scoping trait —
     * login must be able to find a user by phone across all shops before a
     * tenant is known), so route-model-binding here does NOT auto-scope.
     * Every method must verify shop ownership explicitly before touching
     * the record — this is exactly that check.
     */
    private function assertOwnedByCurrentShop(User $staff): void
    {
        abort_unless($staff->shop_id === Tenancy::id(), 404);
    }

    public function index()
    {
        return Inertia::render('App/Staff/Index', [
            'cashiers' => User::where('shop_id', Tenancy::id())->where('role', 'staff')->orderBy('name')->get(),
            'staffPermissions' => config('staff_permissions'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:4'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('staff_permissions')))],
        ]);

        $shopId = Tenancy::id();
        $shop = Shop::whereKey($shopId)->first();

        User::create([
            'shop_id' => $shopId,
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => 'staff',
            'permissions' => $data['permissions'] ?? [],
            'lang' => $shop->lang,
        ]);

        return back()->with('success', 'ক্যাশিয়ার যোগ হয়েছে।');
    }

    public function update(Request $request, User $staff)
    {
        $this->assertOwnedByCurrentShop($staff);
        abort_if($staff->role !== 'staff', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($staff->id)],
            'password' => ['nullable', 'string', 'min:4'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('staff_permissions')))],
        ]);

        $staff->update([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'permissions' => $data['permissions'] ?? [],
            ...(! empty($data['password']) ? ['password' => $data['password']] : []),
        ]);

        return back()->with('success', 'ক্যাশিয়ারের তথ্য আপডেট হয়েছে।');
    }

    public function destroy(User $staff)
    {
        $this->assertOwnedByCurrentShop($staff);
        abort_if($staff->role !== 'staff', 404);

        $staff->delete();

        return back()->with('success', 'ক্যাশিয়ার মুছে ফেলা হয়েছে।');
    }
}
