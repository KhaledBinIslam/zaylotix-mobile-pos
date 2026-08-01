<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function updateVat(Request $request)
    {
        $data = $request->validate([
            'vat_mode' => ['required', 'in:none,turnover,full'],
        ]);

        $shop = Tenancy::shop();
        $shop->update([
            'vat_mode' => $data['vat_mode'],
            'vat_rate' => $data['vat_mode'] === 'full' ? 15 : 0,
            'turnover_rate' => $data['vat_mode'] === 'turnover' ? 3 : 0,
        ]);

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
