<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingController extends Controller
{
    /**
     * `rate` is optional and only applied to whichever field the current
     * mode uses — switching mode alone (no rate passed) never disturbs the
     * shop's previously-saved vat_rate/turnover_rate, so re-enabling VAT
     * later restores the rate they had before turning it off.
     */
    public function updateVat(Request $request)
    {
        $data = $request->validate([
            'vat_mode' => ['required', 'in:none,turnover,full'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $shop = Tenancy::shop();
        $update = ['vat_mode' => $data['vat_mode']];

        if (isset($data['rate'])) {
            if ($data['vat_mode'] === 'full') {
                $update['vat_rate'] = $data['rate'];
            } elseif ($data['vat_mode'] === 'turnover') {
                $update['turnover_rate'] = $data['rate'];
            }
        }

        $shop->update($update);

        return back()->with('success', 'VAT settings saved.');
    }

    /** Owner-configurable — see the migration's comment for why these two rates aren't hardcoded. */
    public function updateLoyalty(Request $request)
    {
        $data = $request->validate([
            'loyalty_earn_rate' => ['required', 'numeric', 'min:0'],
            'loyalty_point_value' => ['required', 'numeric', 'min:0'],
        ]);

        Tenancy::shop()->update($data);

        return back()->with('success', 'লয়্যালটি পয়েন্টের হার সংরক্ষণ করা হয়েছে।');
    }

    /** The number a table order's kitchen ticket gets sent to over WhatsApp — separate from the shop's own printed contact number (`phone`). */
    public function updateKitchenWhatsapp(Request $request)
    {
        $data = $request->validate([
            'kitchen_whatsapp' => ['nullable', 'string', 'max:20'],
        ]);

        Tenancy::shop()->update($data);

        return back()->with('success', 'কিচেনের WhatsApp নম্বর সংরক্ষণ করা হয়েছে।');
    }

    /**
     * Two more restaurant-workflow preferences — see the migration's
     * comment for what each one actually changes (both are UI-only, no
     * billing/printing action is ever blocked by these).
     */
    public function updateRestaurantPrefs(Request $request)
    {
        $data = $request->validate([
            'payment_timing' => ['required', 'in:pay_first,pay_later'],
            'kitchen_print_order' => ['required', 'in:kitchen_first,customer_first'],
        ]);

        Tenancy::shop()->update($data);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    /**
     * Language is a personal preference, not a shop-wide setting — an owner
     * switching to English shouldn't silently flip their cashier's app to
     * English too. `shops.lang` stays untouched here; it's only the
     * *default* a new cashier/owner starts with (see ShopProvisioner,
     * StaffController::store), never force-synced afterward.
     */
    public function updateLang(Request $request)
    {
        $data = $request->validate(['lang' => ['required', 'in:bn,en']]);

        auth('web')->user()->update(['lang' => $data['lang']]);

        return back();
    }

    /** Independent of the admin-controlled camera-scan (`sales_mode`) setting — a shop owner can turn hardware (USB/Bluetooth keyboard-wedge) scanner listening on/off for themselves at any time. */
    public function updateHardwareScanner(Request $request)
    {
        $data = $request->validate([
            'hardware_scanner_enabled' => ['required', 'boolean'],
        ]);

        Tenancy::shop()->update($data);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    /** A custom line printed at the bottom of every memo, replacing the default "ধন্যবাদ! আবার আসবেন 🙏" — see Sales/Show.vue's fallback. */
    public function updateReceiptFooter(Request $request)
    {
        $data = $request->validate([
            'receipt_footer' => ['nullable', 'string', 'max:255'],
        ]);

        Tenancy::shop()->update(['receipt_footer' => $data['receipt_footer'] ?: null]);

        return back()->with('success', 'মেমোর ফুটার সংরক্ষণ করা হয়েছে।');
    }

    /** VAT registration (BIN) number, printed on the receipt only when set — most small shops don't have one. */
    public function updateBinNo(Request $request)
    {
        $data = $request->validate([
            'bin_no' => ['nullable', 'string', 'max:50'],
        ]);

        Tenancy::shop()->update(['bin_no' => $data['bin_no'] ?: null]);

        return back()->with('success', 'BIN নম্বর সংরক্ষণ করা হয়েছে।');
    }

    /**
     * Additive on top of the bill (unlike VAT, which is backed out of an
     * already-inclusive price) — see PosController::checkout() and
     * TableOrderController::bill() for the actual computation. Null turns it
     * off entirely; most non-restaurant shops never set this.
     */
    public function updateServiceCharge(Request $request)
    {
        $data = $request->validate([
            'service_charge_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        Tenancy::shop()->update(['service_charge_rate' => $data['service_charge_rate']]);

        return back()->with('success', 'সার্ভিস চার্জ সংরক্ষণ করা হয়েছে।');
    }

    /** Page with checkbox toggles + a live preview for what the printed memo and barcode label show — see Shop::MEMO_SETTINGS_DEFAULTS/LABEL_SETTINGS_DEFAULTS. */
    public function receiptLabelEdit()
    {
        return Inertia::render('App/Settings/ReceiptLabel');
    }

    /** Extra shop-identity fields a receipt can optionally print — none of these existed before, so every one is nullable and off by default (see memo_settings' show_* toggles). */
    public function updateBusinessInfo(Request $request)
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'trade_license_no' => ['nullable', 'string', 'max:100'],
            'tin_no' => ['nullable', 'string', 'max:100'],
        ]);

        Tenancy::shop()->update(array_map(fn ($v) => $v ?: null, $data));

        return back()->with('success', 'তথ্য সংরক্ষণ করা হয়েছে।');
    }

    /** Replaces the whole memo_settings blob — the frontend always sends every key (merged with Shop::MEMO_SETTINGS_DEFAULTS), never a partial patch, so there's nothing here to merge server-side. */
    public function updateMemoSettings(Request $request)
    {
        $data = $request->validate([
            'show_email' => ['required', 'boolean'],
            'show_website' => ['required', 'boolean'],
            'show_bin' => ['required', 'boolean'],
            'show_trade_license' => ['required', 'boolean'],
            'show_tin' => ['required', 'boolean'],
            'show_cashier' => ['required', 'boolean'],
            'show_customer_phone' => ['required', 'boolean'],
            'show_payment_method' => ['required', 'boolean'],
            'show_tendered_change' => ['required', 'boolean'],
            'show_vat_inclusive_note' => ['required', 'boolean'],
            'show_exchange_policy' => ['required', 'boolean'],
            'exchange_policy_text' => ['nullable', 'string', 'max:500'],
            'paper_width' => ['required', 'in:58mm,80mm,a4'],
        ]);

        Tenancy::shop()->update(['memo_settings' => $data]);

        return back()->with('success', 'মেমো সেটিংস সংরক্ষণ করা হয়েছে।');
    }

    /** Replaces the whole label_settings blob — see updateMemoSettings()'s comment, same "always full payload" contract. */
    public function updateLabelSettings(Request $request)
    {
        $data = $request->validate([
            'size' => ['required', 'in:38x25,50x25,40x30,custom'],
            'custom_width_mm' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'custom_height_mm' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'per_row' => ['required', 'integer', 'in:1,2'],
            'show_shop_name' => ['required', 'boolean'],
            'show_product_name' => ['required', 'boolean'],
            'show_variant' => ['required', 'boolean'],
            'price_mode' => ['required', 'in:both,single,none'],
            'show_barcode_number' => ['required', 'boolean'],
        ]);

        if ($data['size'] === 'custom' && (! $data['custom_width_mm'] || ! $data['custom_height_mm'])) {
            return back()->withErrors(['custom_width_mm' => 'Custom সাইজের জন্য width ও height দুটোই দিতে হবে।']);
        }

        Tenancy::shop()->update(['label_settings' => $data]);

        return back()->with('success', 'লেবেল সেটিংস সংরক্ষণ করা হয়েছে।');
    }

    /** Shop logo, printed on POS receipts and barcode labels once uploaded. */
    public function updateLogo(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:1024', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $shop = Tenancy::shop();

        if ($shop->logo_path) {
            Storage::disk('public')->delete($shop->logo_path);
        }

        $path = $request->file('logo')->store('shop-logos', 'public');
        $shop->update(['logo_path' => $path]);

        return back()->with('success', 'লোগো আপলোড হয়েছে');
    }
}
