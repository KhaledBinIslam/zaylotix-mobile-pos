<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Platform-wide branding — the logo shown on the admin panel and, as
 * "product owner" credit, on every shop's printed memo/labels — plus the
 * subscription/trial reminder threshold used by SendPaymentReminders.
 */
class SiteSettingController extends Controller
{
    public function edit()
    {
        return Inertia::render('Admin/SiteSettings/Edit', [
            'setting' => SiteSetting::current(),
        ]);
    }

    public function updateReminderDays(Request $request)
    {
        $data = $request->validate([
            'reminder_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        SiteSetting::current()->update($data);

        return back()->with('success', 'রিমাইন্ডারের দিনসংখ্যা সংরক্ষণ করা হয়েছে।');
    }

    /**
     * The number the public landing page's "WhatsApp" button opens a chat
     * with (wa.me/<this>) — platform-wide contact for shops interested in
     * signing up. Digits only (with country code), no '+' or spaces, matching
     * the wa.me link format used elsewhere in the app (kitchen_whatsapp etc).
     */
    public function updateWhatsappContact(Request $request)
    {
        $data = $request->validate([
            'whatsapp_contact' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
        ]);

        SiteSetting::current()->update($data);

        return back()->with('success', 'WhatsApp নম্বর সংরক্ষণ করা হয়েছে।');
    }

    public function update(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'image', 'max:1024', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $setting = SiteSetting::current();

        if ($setting->logo_path) {
            Storage::disk('public')->delete($setting->logo_path);
        }

        $path = $request->file('logo')->store('platform', 'public');
        $setting->update(['logo_path' => $path]);

        return back()->with('success', 'Logo uploaded.');
    }

    public function destroy()
    {
        $setting = SiteSetting::current();

        if ($setting->logo_path) {
            Storage::disk('public')->delete($setting->logo_path);
            $setting->update(['logo_path' => null]);
        }

        return back()->with('success', 'Logo removed.');
    }
}
