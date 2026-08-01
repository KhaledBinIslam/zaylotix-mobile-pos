<?php

namespace App\Support\Gateways;

use App\Models\GatewayPayment;
use Illuminate\Http\Request;

/**
 * A single, small contract every payment gateway implements — this is
 * what makes "add a new gateway later" a matter of writing one new class
 * and registering it in GatewayManager::DRIVERS, not touching checkout,
 * webhook routing, or the settings UI at all.
 */
interface GatewayDriver
{
    /** Which config keys this provider's credentials array must have — drives both settings-form validation and the "is this configured correctly" check, in one place. */
    public function requiredCredentialFields(): array;

    /**
     * Starts a payment for an already-priced, already-validated
     * GatewayPayment row. Must return a URL to send the customer's browser
     * to — every provider here is integrated via its hosted-checkout/
     * redirect flow (bKash via its documented "Checkout (URL)" flow, not
     * the JS-SDK widget) specifically so one interface covers all three.
     *
     * @param  array<string,string>  $credentials  this shop's own decrypted merchant credentials
     */
    public function initiate(GatewayPayment $payment, array $credentials): string;

    /**
     * Verifies an incoming webhook/callback request is genuinely from the
     * gateway (never trust the payload alone — signature/hash or a
     * server-to-server verification call, per provider) and, if valid,
     * returns the normalized result. Returns null for anything that fails
     * verification — the caller must treat null as "reject, do not act on
     * this payload under any circumstances."
     *
     * @return array{reference: string, gateway_transaction_id: ?string, amount: float, status: string}|null
     */
    public function verifyWebhook(Request $request, array $credentials): ?array;
}
