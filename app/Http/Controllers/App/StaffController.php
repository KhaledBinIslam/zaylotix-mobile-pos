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
 * Owner-managed cashier accounts, gated behind the `cashier_management`
 * feature (Starter tier never reaches these routes at all — see the
 * `feature:cashier_management` middleware group in routes/web.php).
 *
 * Above that, `shops.staff_limit` caps how many cashier accounts a shop
 * may hold: Business tier is meant to stop at a 2nd staff account,
 * Ultimate at 10 (see the pricing flyer — "২য় স্টাফ অ্যাকাউন্ট" vs
 * "সর্বোচ্চ ১০টি স্টাফ অ্যাকাউন্ট"). It's a plain nullable integer, not a
 * tier flag, deliberately — admin can raise or lower it per shop from
 * Admin/Shops/Edit.vue regardless of package for a negotiated deal,
 * exactly the flexibility asked for. A shop with it left null falls
 * back to DEFAULT_STAFF_CAP (the Business default). The cap only ever
 * blocks *creating* a new cashier past the limit — it never touches
 * cashiers a shop already has, even if a later admin edit lowers the
 * limit below the current count.
 *
 * The owner decides exactly which app sections (config/staff_permissions.php)
 * each cashier can reach; every one of those sections is enforced
 * server-side by the `perm:<key>` middleware on the relevant routes, not
 * just hidden in the nav, the same way shop-level `feature:<key>` gates
 * work for admin grants.
 */
class StaffController extends Controller
{
    /** Fallback cap when a shop's `staff_limit` is null — the Business-tier default. */
    public const DEFAULT_STAFF_CAP = 2;

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
        $shop = Shop::whereKey(Tenancy::id())->first();
        $count = User::where('shop_id', Tenancy::id())->where('role', 'staff')->count();

        return Inertia::render('App/Staff/Index', [
            'cashiers' => User::where('shop_id', Tenancy::id())->where('role', 'staff')->orderBy('name')->get(),
            'staffPermissions' => config('staff_permissions'),
            'cashierCount' => $count,
            'cashierLimit' => $shop->staff_limit ?? self::DEFAULT_STAFF_CAP,
        ]);
    }

    public function store(Request $request)
    {
        $shopId = Tenancy::id();
        $shop = Shop::whereKey($shopId)->first();
        $limit = $shop->staff_limit ?? self::DEFAULT_STAFF_CAP;
        $current = User::where('shop_id', $shopId)->where('role', 'staff')->count();

        if ($current >= $limit) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => "আপনার প্যাকেজে সর্বোচ্চ {$limit} জন ক্যাশিয়ার যোগ করা যাবে। বেশি দরকার হলে অ্যাডমিনের সাথে যোগাযোগ করুন।",
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:4'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(config('staff_permissions')))],
        ]);

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
