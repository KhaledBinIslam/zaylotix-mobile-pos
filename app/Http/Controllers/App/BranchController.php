<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\CatalogSync;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets an owner operate as a different branch of their own business without
 * a fresh login -- same idea as Admin\ImpersonationController's session
 * override, one level lighter (no guard/login change; the owner stays
 * logged in as themselves, only Tenancy::id()'s resolution changes for the
 * rest of this session). Staff never see this -- they're always fixed to
 * whichever single shop_id their account was created under.
 */
class BranchController extends Controller
{
    public function switch(Request $request, Shop $branch)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user?->role === 'owner', 403);

        $ownShop = Shop::withoutGlobalScopes()->find($user->shop_id);
        $ownRoot = $ownShop->parent_shop_id ?? $ownShop->id;
        $branchRoot = $branch->parent_shop_id ?? $branch->id;
        abort_unless($ownRoot === $branchRoot, 403);

        $request->session()->put('active_branch_id', $branch->id);

        return redirect()->route('app.home');
    }

    /**
     * Owner-triggered — re-copies the main shop's current products/
     * categories/units into every sibling branch (adds anything missing,
     * updates name/price on anything matched by barcode/name; never
     * touches a branch's own stock). Only callable while "on" a main shop
     * itself (parent_shop_id null) that actually has branches.
     */
    public function syncCatalog(Request $request)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user?->role === 'owner', 403);

        $mainShop = Shop::withoutGlobalScopes()->find(Tenancy::id());
        abort_if(! $mainShop || $mainShop->parent_shop_id, 422, 'এটি একটি প্রধান দোকান নয়।');

        $branches = Shop::withoutGlobalScopes()->where('parent_shop_id', $mainShop->id)->get();
        abort_if($branches->isEmpty(), 422, 'কোনো শাখা নেই।');

        foreach ($branches as $branch) {
            CatalogSync::syncToBranch($mainShop, $branch);
        }

        return back()->with('success', "{$branches->count()} টি শাখায় ক্যাটালগ সিঙ্ক করা হয়েছে।");
    }
}
