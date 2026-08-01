<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CheckoutRequest;
use App\Models\GatewayPayment;
use App\Models\PaymentGatewayCredential;
use App\Support\Gateways\GatewayManager;
use App\Support\Gateways\GatewayPricePreview;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Starts a "pay via gateway" checkout — reuses CheckoutRequest so the cart
 * shape/validation is identical to a normal checkout, just without a
 * `payments` array (there's nothing to tender yet; the gateway is the
 * tender). Never creates a Sale or moves stock itself — that only happens
 * once GatewayWebhookController confirms the money actually arrived.
 */
class GatewayCheckoutController extends Controller
{
    public function initiate(CheckoutRequest $request, string $provider)
    {
        if (! in_array($provider, GatewayManager::providers(), true)) {
            abort(404);
        }

        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();

        $credential = PaymentGatewayCredential::where('shop_id', $shopId)
            ->where('provider', $provider)->where('is_active', true)->first();
        if (! $credential) {
            abort(422, ucfirst($provider).' এই দোকানে সংযুক্ত করা নেই — ম্যানুয়াল পেমেন্ট ব্যবহার করুন।');
        }

        $data = $request->validated();
        // this is a gateway-paid checkout — any client-submitted tender
        // list is meaningless here and must never leak into the priced/
        // stored payload (the real "payment" is the gateway capture itself)
        unset($data['payments']);

        $total = GatewayPricePreview::total($data, $shopId, $userId);
        if ($total <= 0) {
            abort(422, 'এই কার্টের মোট মূল্য শূন্য — গেটওয়ে পেমেন্ট প্রযোজ্য নয়।');
        }

        $payment = GatewayPayment::create([
            'shop_id' => $shopId,
            'user_id' => $userId,
            'provider' => $provider,
            'reference' => 'ZLX-'.now()->format('YmdHis').'-'.Str::random(8),
            'amount' => $total,
            'status' => 'pending',
            'checkout_payload' => $data,
        ]);

        $redirectUrl = GatewayManager::driver($provider)->initiate($payment, $credential->credentials);

        return response()->json(['redirect_url' => $redirectUrl, 'reference' => $payment->reference]);
    }

    /** Lets the POS page poll after redirecting back from the gateway, to know whether the webhook has confirmed yet (the browser callback and the async webhook don't always land in a guaranteed order). */
    public function status(string $reference)
    {
        $payment = GatewayPayment::where('shop_id', Tenancy::id())->where('reference', $reference)->firstOrFail();

        return response()->json([
            'status' => $payment->status,
            'sale_id' => $payment->sale_id,
        ]);
    }
}
