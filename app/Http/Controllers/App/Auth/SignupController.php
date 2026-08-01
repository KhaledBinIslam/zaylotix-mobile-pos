<?php

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Support\FeatureRecommendations;
use App\Support\ShopProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Public self-serve signup — a prospective shop owner creates their own
 * trial shop without an admin manually provisioning it first (previously
 * the only way onto the platform). Reuses ShopProvisioner exactly like the
 * admin "create shop" flow, so a self-signed-up shop is indistinguishable
 * from an admin-created one — same trial length, same recommended feature
 * set for the chosen business type, same onboarding wizard on first login.
 */
class SignupController extends Controller
{
    public function create()
    {
        return Inertia::render('App/Auth/Signup', [
            'businessTypes' => BusinessType::where('is_active', true)->orderBy('label_bn')->get(['id', 'slug', 'label_bn', 'label_en']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:shops,phone', 'unique:users,phone'],
            'business_type_id' => ['required', 'exists:business_types,id'],
            'owner_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:4'],
            'area' => ['nullable', 'string', 'max:255'],
        ], [
            'phone.unique' => 'এই ফোন নম্বর দিয়ে আগেই একটি অ্যাকাউন্ট আছে।',
        ]);

        $businessType = BusinessType::findOrFail($data['business_type_id']);

        $shop = ShopProvisioner::provision(
            shopAttrs: [
                'business_type_id' => $businessType->id,
                'name' => $data['name'],
                'name_en' => $data['name'],
                'phone' => $data['phone'],
                'area' => $data['area'] ?? null,
                'owner_name' => $data['owner_name'],
                'sales_mode' => 'both',
                'lang' => 'bn',
                'plan' => 'trial',
                'status' => 'active',
                'subscription_start' => now()->toDateString(),
                // same 14-day trial the admin flow and demo shops use —
                'subscription_expiry' => now()->addDays(14)->toDateString(),
            ],
            ownerName: $data['owner_name'],
            ownerPhone: $data['phone'],
            ownerEmail: null,
            ownerPassword: $data['password'],
            featureKeys: FeatureRecommendations::forBusinessType($businessType->slug),
        );

        $owner = $shop->users()->where('role', 'owner')->first();
        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()->route('app.home')->with('success', 'স্বাগতম! আপনার দোকান তৈরি হয়েছে — ১৪ দিনের ফ্রি ট্রায়াল শুরু হলো।');
    }
}
