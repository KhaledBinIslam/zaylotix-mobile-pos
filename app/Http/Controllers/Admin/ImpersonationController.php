<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets an admin log in AS a shop's owner for support/troubleshooting,
 * without needing that owner's password. The admin's own 'admin' guard
 * session is untouched (it's a separate guard/session key from 'web'), so
 * stopping impersonation always lands back in the admin panel already
 * logged in — never a surprise re-login.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, Shop $shop)
    {
        $owner = $shop->users()->where('role', 'owner')->first();

        if (! $owner) {
            return back()->withErrors(['shop' => 'এই দোকানের কোনো owner ইউজার পাওয়া যায়নি।']);
        }

        $admin = Auth::guard('admin')->user();
        AdminActivity::log('impersonate.start', "Admin '{$admin->name}' started impersonating shop '{$shop->name}'.", $shop, ['owner_id' => $owner->id]);

        $request->session()->put('impersonating_admin_id', $admin->id);
        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()->route('app.home');
    }

    public function stop(Request $request)
    {
        $adminId = $request->session()->get('impersonating_admin_id');
        if (! $adminId) {
            return redirect()->route('app.home');
        }

        $shop = Auth::guard('web')->user()?->shop;
        Auth::guard('web')->logout();
        $request->session()->forget('impersonating_admin_id');
        $request->session()->regenerate();

        if ($shop) {
            AdminActivity::log('impersonate.stop', "Stopped impersonating shop '{$shop->name}'.", $shop);
        }

        return redirect()->route('admin.shops.index');
    }
}
