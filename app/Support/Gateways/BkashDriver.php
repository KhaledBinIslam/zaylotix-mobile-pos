<?php

namespace App\Support\Gateways;

use App\Models\GatewayPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * bKash Tokenized Checkout — specifically the "Checkout (URL)" variant
 * (a plain redirect URL), not the JS-SDK widget, so it fits the same
 * "return a URL, redirect the browser" shape every driver here uses.
 *
 * bKash's callback is just a browser redirect carrying `paymentID` and
 * `status` in the query string — NOT an authenticated server-to-server
 * webhook, and not something to trust on its own (anyone could hit the
 * callback URL with a fabricated paymentID). The actual trust boundary is
 * the Execute Payment API call verifyWebhook() makes: bKash's own server
 * confirms (or denies) that specific paymentID actually completed, using
 * a freshly-granted token, before this ever reports success.
 *
 * NOTE: base URLs / exact endpoint paths and payload field names should be
 * re-checked against bKash's current merchant developer docs before a
 * shop goes live with this — bKash has revised its tokenized-checkout API
 * version path before, and this was written without a live sandbox
 * account to test end-to-end against.
 */
class BkashDriver implements GatewayDriver
{
    public function requiredCredentialFields(): array
    {
        return ['app_key', 'app_secret', 'username', 'password', 'sandbox'];
    }

    private function baseUrl(array $credentials): string
    {
        return ($credentials['sandbox'] ?? true)
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta';
    }

    private function grantToken(array $credentials): string
    {
        $response = Http::withHeaders([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        ])->post($this->baseUrl($credentials).'/tokenized/checkout/token/grant', [
            'app_key' => $credentials['app_key'],
            'app_secret' => $credentials['app_secret'],
        ]);

        $token = $response->json('id_token');
        if (! $response->ok() || ! $token) {
            Log::error('bKash token grant failed', ['response' => $response->json()]);
            throw new \RuntimeException('bKash টোকেন নেওয়া যায়নি।');
        }

        return $token;
    }

    public function initiate(GatewayPayment $payment, array $credentials): string
    {
        $token = $this->grantToken($credentials);

        $response = Http::withHeaders([
            'Authorization' => $token,
            'X-App-Key' => $credentials['app_key'],
        ])->post($this->baseUrl($credentials).'/tokenized/checkout/create', [
            'mode' => '0011',
            'payerReference' => (string) $payment->shop_id,
            'callbackURL' => route('gateway.callback', ['provider' => 'bkash']),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $payment->reference,
        ]);

        $data = $response->json();
        if (! $response->ok() || empty($data['bkashURL'])) {
            Log::error('bKash create-payment failed', ['response' => $data]);
            throw new \RuntimeException('bKash পেমেন্ট শুরু করা যায়নি।');
        }

        // paymentID is bKash's own identifier for this attempt — needed
        // later to Execute it; our own `reference` (merchantInvoiceNumber)
        // is what ties it back to this GatewayPayment row
        $payment->update(['gateway_transaction_id' => $data['paymentID']]);

        return $data['bkashURL'];
    }

    public function verifyWebhook(Request $request, array $credentials): ?array
    {
        $paymentId = $request->input('paymentID');
        $status = $request->input('status');
        if (! $paymentId || $status !== 'success') {
            return null;
        }

        $token = $this->grantToken($credentials);

        $response = Http::withHeaders([
            'Authorization' => $token,
            'X-App-Key' => $credentials['app_key'],
        ])->post($this->baseUrl($credentials).'/tokenized/checkout/execute', [
            'paymentID' => $paymentId,
        ]);

        $data = $response->json();
        if (! $response->ok() || ($data['transactionStatus'] ?? null) !== 'Completed') {
            Log::warning('bKash execute did not confirm completion', ['paymentID' => $paymentId, 'response' => $data]);

            return null;
        }

        return [
            'reference' => $data['merchantInvoiceNumber'] ?? '',
            'gateway_transaction_id' => $data['trxID'] ?? $paymentId,
            'amount' => (float) ($data['amount'] ?? 0),
            'status' => 'success',
        ];
    }
}
