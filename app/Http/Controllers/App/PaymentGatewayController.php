<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayCredential;
use App\Support\Gateways\GatewayManager;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Bring-your-own-key payment gateway settings — each shop owner connects
 * their OWN bKash/Nagad/SSLCommerz merchant account. Nothing here ever
 * sends a raw secret back to the browser once saved: index() only returns
 * PaymentGatewayCredential::maskedSummary(), never ->credentials itself.
 * Every route this controller serves is owner-only (see routes/web.php's
 * `owner` middleware) — a cashier login must never see or touch merchant
 * API secrets.
 */
class PaymentGatewayController extends Controller
{
    public function index()
    {
        $shopId = Tenancy::id();
        $credentials = PaymentGatewayCredential::where('shop_id', $shopId)->get()
            ->keyBy('provider')
            ->map(fn (PaymentGatewayCredential $c) => [
                'provider' => $c->provider,
                'is_active' => $c->is_active,
                'masked_summary' => $c->maskedSummary(),
            ]);

        return response()->json([
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

        PaymentGatewayCredential::updateOrCreate(
            ['shop_id' => Tenancy::id(), 'provider' => $provider],
            ['credentials' => $data, 'is_active' => true]
        );

        return back()->with('success', ucfirst($provider).' সংযুক্ত করা হয়েছে।');
    }

    public function toggle(Request $request, string $provider)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $credential = PaymentGatewayCredential::where('shop_id', Tenancy::id())->where('provider', $provider)->firstOrFail();
        $credential->update(['is_active' => $data['is_active']]);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    public function destroy(string $provider)
    {
        PaymentGatewayCredential::where('shop_id', Tenancy::id())->where('provider', $provider)->delete();

        return back()->with('success', 'সংযোগ বিচ্ছিন্ন করা হয়েছে।');
    }
}
