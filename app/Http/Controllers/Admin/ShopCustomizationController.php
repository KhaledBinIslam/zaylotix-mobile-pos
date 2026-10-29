<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\AdminActivity;
use App\Support\ShopCustomization;
use Illuminate\Http\Request;

/**
 * Phase 40 — the admin-side "কাস্টমাইজেশন" panel's two write actions.
 * Super-admin-only (routes/admin.php): a support admin sees the effective
 * values on the shop edit page but can't change another shop's output.
 * Everything else about a shop's customization is edited where the owner
 * edits it (the shop-side settings pages, reachable via impersonation,
 * with live previews) — this controller only covers what an owner can't
 * do for themselves: a business-level header set from the admin panel,
 * and the one-click "ডিফল্টে ফেরান" kill-switch per setting.
 */
class ShopCustomizationController extends Controller
{
    public function updateLabelHeader(Request $request, Shop $shop)
    {
        $data = $request->validate([
            'label_header' => ['nullable', 'string', 'max:60'],
        ]);

        // business-level by design (Shop::labelHeader()) — a branch's own
        // value would never be read, so the admin UI only offers this on a
        // main shop; guard it server-side too
        if ($shop->parent_shop_id) {
            return back()->with('error', 'লেবেল হেডার মূল শপে সেট করুন — ব্রাঞ্চ সবসময় মূল শপের মান ব্যবহার করে।');
        }

        $old = $shop->label_header;
        $shop->update(['label_header' => $data['label_header'] ?: null]);

        AdminActivity::log('shop.customization', "Shop '{$shop->name}' label header: '{$old}' → '{$shop->label_header}'", $shop, [
            'key' => 'label_header', 'old' => $old, 'new' => $shop->label_header,
        ]);

        return back()->with('success', 'লেবেল হেডার সংরক্ষণ করা হয়েছে।');
    }

    /** One-click "ডিফল্টে ফেরান" for a single customization key — the per-setting kill-switch. */
    public function reset(Request $request, Shop $shop)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'in:'.implode(',', array_keys(ShopCustomization::RESETTABLE_COLUMNS))],
        ]);

        $updates = [];
        foreach (ShopCustomization::RESETTABLE_COLUMNS[$data['key']] as $column) {
            // barcode_include_size is a NOT NULL boolean (default false) —
            // its "default" is false, not null
            $updates[$column] = $column === 'barcode_include_size' ? false : null;
        }
        $shop->update($updates);

        AdminActivity::log('shop.customization', "Shop '{$shop->name}' customization '{$data['key']}' reset to default.", $shop, [
            'key' => $data['key'],
        ]);

        return back()->with('success', "'{$data['key']}' ডিফল্টে ফেরানো হয়েছে।");
    }
}
