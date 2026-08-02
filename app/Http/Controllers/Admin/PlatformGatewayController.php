<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformGatewayCredential;
use App\Support\Gateways\GatewayManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Zaylotix's OWN bKash/Nagad/SSLCommerz merchant account -- for shop owners
 * paying their subscription (see App\Http\Controllers\App\SubscriptionRenewalController).
 * Mirrors App\Http\Controllers\App\PaymentGatewayController's shape closely
 * (same requiredCredentialFields()-driven dynamic validation), but single
 * row per provider instead of per-shop, and super_admin-only.
 */
class PlatformGatewayController extends Controller
{
    public function index()
    {
        $credentials = PlatformGatewayCredential::get()->keyBy('provider')
            ->map(fn (PlatformGatewayCredential $c) => [
                'provider' => $c->provider,
                'is_active' => $c->is_active,
                'masked_summary' => $c->maskedSummary(),
            ]);

        return Inertia::render('Admin/PlatformGateway/Index', [
            'providers' => GatewayManager::providers(),
            'configured' => $credentials,
        ]);
    }

    public function store(Request $request, string $provider)
    {
        if (! in_array($provider, GatewayManager::providers(), true)) {
            abort(404);
        }

        $driver = GatewayManager::driver($provider);
        $rules = collect($driver->requiredCredentialFields())
            ->mapWithKeys(fn ($field) => [$field => [$field === 'sandbox' ? 'boolean' : 'required', $field === 'sandbox' ? 'boolean' : 'string']])
            ->all();

        $data = $request->validate($rules);

        PlatformGatewayCredential::updateOrCreate(
            ['provider' => $provider],
            ['credentials' => $data, 'is_active' => true]
        );

        return back()->with('success', ucfirst($provider).' সংযুক্ত করা হয়েছে।');
    }

    public function toggle(Request $request, string $provider)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $credential = PlatformGatewayCredential::where('provider', $provider)->firstOrFail();
        $credential->update(['is_active' => $data['is_active']]);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    public function destroy(string $provider)
    {
        PlatformGatewayCredential::where('provider', $provider)->delete();

        return back()->with('success', 'সংযোগ বিচ্ছিন্ন করা হয়েছে।');
    }
}
