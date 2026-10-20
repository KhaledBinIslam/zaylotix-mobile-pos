<?php

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use App\Models\Ambassador;
use App\Models\BusinessType;
use App\Support\AmbassadorCommissionService;
use App\Support\DefaultLandingRoute;
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
    public function create(Request $request)
    {
        return Inertia::render('App/Auth/Signup', [
            'businessTypes' => BusinessType::where('is_active', true)->orderBy('label_bn')->get(['id', 'slug', 'label_bn', 'label_en']),
            // see docs/FIX_PLAN.md's "অ্যাম্বাসেডর টুলস" design note —
            // carried through as a hidden form field (Signup.vue) so it
            // survives a validation-error round trip, not just the first
            // page load's query string
            'ref' => trim((string) $request->get('ref', '')) ?: null,
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
            'ref' => ['nullable', 'string', 'max:50'],
        ], [
            'phone.unique' => 'এই ফোন নম্বর দিয়ে আগেই একটি অ্যাকাউন্ট আছে।',
        ]);

        $businessType = BusinessType::findOrFail($data['business_type_id']);

        // A bad/stale/typo'd ref simply never matches anything — this is
        // never a hard validation error, since the shop itself has no
        // idea their referral code didn't resolve and shouldn't be
        // blocked from signing up because of it.
        $ambassador = ! empty($data['ref'])
            ? Ambassador::where('referral_code', $data['ref'])->where('status', 'active')->first()
            : null;

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
                'account_type' => 'trial',
                'status' => 'active',
                'subscription_start' => now()->toDateString(),
                // same 14-day trial the admin flow and demo shops use —
                'subscription_expiry' => now()->addDays(14)->toDateString(),
                'brought_by_ambassador_id' => $ambassador?->id,
            ],
            ownerName: $data['owner_name'],
            ownerPhone: $data['phone'],
            ownerEmail: null,
            ownerPassword: $data['password'],
            featureKeys: FeatureRecommendations::forBusinessType($businessType->slug),
        );

        // Same two effects Admin\AmbassadorController::assignToShop() has
        // when brought_by_ambassador_id transitions null -> set: the
        // one-time bonus commission row, and becoming the shop's
        // currently-assigned ambassador (drives the recurring %) — a
        // self-signup via referral link should credit exactly as much as
        // an admin manually tying the two together would.
        if ($ambassador) {
            AmbassadorCommissionService::handleBroughtByChange($shop, null, $ambassador->id);
            AmbassadorCommissionService::assign($shop, $ambassador, 'Self-signup via referral link');
        }

        $owner = $shop->users()->where('role', 'owner')->first();
        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()->route(DefaultLandingRoute::for($owner))->with('success', 'স্বাগতম! আপনার দোকান তৈরি হয়েছে — ১৪ দিনের ফ্রি ট্রায়াল শুরু হলো।');
    }
}
