<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GatewayPayment;
use App\Models\PlatformGatewayCredential;
use App\Support\Gateways\GatewayManager;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Owner-initiated subscription renewal via Zaylotix's OWN merchant account
 * (see PlatformGatewayCredential / Admin\PlatformGatewayController) --
 * completely separate from GatewayCheckoutController, which is a shop's own
 * account taking payment FROM their customers. Charges the shop's current
 * monthly_fee; a cash/manual payment still goes through the existing
 * admin-recorded Admin\SubscriptionController flow untouched.
 *
 * Reuses GatewayPayment as-is (sale_id stays null here) rather than a
 * parallel model -- every driver only ever reads amount/reference/shop_id
 * off it, never anything sale-shaped, so the exact same
 * initiate()/verifyWebhook() contract that already works for customer
 * checkouts works unchanged here too. See GatewayWebhookController's
 * `checkout_payload['purpose']` branch for what happens once the gateway
 * confirms payment.
 */
class SubscriptionRenewalController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();

        $active = PlatformGatewayCredential::where('is_active', true)->pluck('provider')->values();

        return response()->json([
            'plan' => $shop->plan,
            'monthly_fee' => (float) $shop->monthly_fee,
            'subscription_expiry' => $shop->subscription_expiry?->toDateString(),
            'providers' => $active,
        ]);
    }

    public function initiate(string $provider)
    {
        if (! in_array($provider, GatewayManager::providers(), true)) {
            abort(404);
        }

        $shopId = Tenancy::id();
        $shop = Tenancy::shop();
        $userId = Auth::guard('web')->id();

        $credential = PlatformGatewayCredential::where('provider', $provider)->where('is_active', true)->first();
        if (! $credential) {
            abort(422, ucfirst($provider).' এখনো সংযুক্ত করা নেই — একটু পর আবার চেষ্টা করুন বা ম্যানুয়ালি যোগাযোগ করুন।');
        }

        $amount = (float) $shop->monthly_fee;
        if ($amount <= 0) {
            abort(422, 'এই দোকানের মাসিক ফি নির্ধারিত নেই — সাপোর্টে যোগাযোগ করুন।');
        }

        $payment = GatewayPayment::create([
            'shop_id' => $shopId,
            'user_id' => $userId,
            'provider' => $provider,
            'reference' => 'ZLXSUB-'.now()->format('YmdHis').'-'.Str::random(8),
            'amount' => $amount,
            'status' => 'pending',
            'checkout_payload' => ['purpose' => 'subscription_renewal', 'months' => 1],
        ]);

        $redirectUrl = GatewayManager::driver($provider)->initiate($payment, $credential->credentials);

        return response()->json(['redirect_url' => $redirectUrl, 'reference' => $payment->reference]);
    }

    /** Lets the renewal UI poll after redirecting back from the gateway — same reasoning as GatewayCheckoutController::status(). */
    public function status(string $reference)
    {
        $payment = GatewayPayment::where('shop_id', Tenancy::id())->where('reference', $reference)->firstOrFail();

        return response()->json(['status' => $payment->status]);
    }
}
