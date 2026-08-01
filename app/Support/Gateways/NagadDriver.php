<?php

namespace App\Support\Gateways;

use App\Models\GatewayPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Nagad's Merchant Checkout API — the one driver of the three built around
 * RSA sign/encrypt rather than a plain shared secret: every request body
 * is encrypted with NAGAD's public key and signed with the MERCHANT's own
 * private key, so credentials here are a full PEM keypair, not just an
 * id/password pair.
 *
 * verifyWebhook() never trusts the browser-redirect query string on its
 * own (same reasoning as the bKash/SSLCommerz drivers) — it calls Nagad's
 * own Verify Payment endpoint server-to-server and only reports success if
 * THAT independently confirms completion.
 *
 * NOTE: written from documented shape (initialize → complete → verify,
 * RSA-OAEP-less PKCS1 encrypt + SHA256 sign) without a live Nagad sandbox
 * account to test against — re-verify exact endpoint paths/field casing
 * against the current merchant integration guide before relying on this
 * for a real transaction.
 */
class NagadDriver implements GatewayDriver
{
    public function requiredCredentialFields(): array
    {
        return ['merchant_id', 'merchant_number', 'public_key', 'private_key', 'sandbox'];
    }

    private function baseUrl(array $credentials): string
    {
        return ($credentials['sandbox'] ?? true)
            ? 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0'
            : 'https://api.mynagad.com/remote-payment-gateway-1.0';
    }

    private function encryptWithNagadKey(string $plaintext, string $publicKeyPem): string
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if (! $key) {
            throw new \RuntimeException('Nagad public key পড়া যায়নি — সঠিক PEM ফরম্যাটে দেওয়া আছে কিনা দেখুন।');
        }
        openssl_public_encrypt($plaintext, $encrypted, $key, OPENSSL_PKCS1_PADDING);

        return base64_encode($encrypted);
    }

    private function signWithMerchantKey(string $plaintext, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if (! $key) {
            throw new \RuntimeException('Nagad private key পড়া যায়নি — সঠিক PEM ফরম্যাটে দেওয়া আছে কিনা দেখুন।');
        }
        openssl_sign($plaintext, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    public function initiate(GatewayPayment $payment, array $credentials): string
    {
        $merchantId = $credentials['merchant_id'];
        $orderId = $payment->reference;
        $dateTime = now()->format('YmdHis');
        $challenge = Str::random(40);

        $sensitiveData = json_encode(['merchantId' => $merchantId, 'datetime' => $dateTime, 'orderId' => $orderId, 'challenge' => $challenge]);
        $encryptedSensitive = $this->encryptWithNagadKey($sensitiveData, $credentials['public_key']);
        $signature = $this->signWithMerchantKey($sensitiveData, $credentials['private_key']);

        $initResponse = Http::withHeaders(['X-KM-Api-Version' => 'v-0.2.0'])
            ->post($this->baseUrl($credentials)."/api/dfs/check-out/initialize/{$merchantId}/{$orderId}", [
                'accountNumber' => $credentials['merchant_number'],
                'dateTime' => $dateTime,
                'sensitiveData' => $encryptedSensitive,
                'signature' => $signature,
            ]);

        $initData = $initResponse->json();
        $paymentRefId = $initData['paymentReferenceId'] ?? null;
        if (! $initResponse->ok() || ! $paymentRefId) {
            Log::error('Nagad initialize failed', ['response' => $initData]);
            throw new \RuntimeException('Nagad পেমেন্ট শুরু করা যায়নি।');
        }

        $completeSensitive = json_encode([
            'merchantId' => $merchantId, 'orderId' => $orderId,
            'currencyCode' => '050', 'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'challenge' => $challenge,
        ]);
        $encryptedComplete = $this->encryptWithNagadKey($completeSensitive, $credentials['public_key']);
        $completeSignature = $this->signWithMerchantKey($completeSensitive, $credentials['private_key']);

        $completeResponse = Http::post($this->baseUrl($credentials)."/api/dfs/check-out/complete/{$paymentRefId}", [
            'sensitiveData' => $encryptedComplete,
            'signature' => $completeSignature,
            'merchantCallbackURL' => route('gateway.callback', ['provider' => 'nagad']),
        ]);

        $completeData = $completeResponse->json();
        if (! $completeResponse->ok() || empty($completeData['callBackUrl'])) {
            Log::error('Nagad complete failed', ['response' => $completeData]);
            throw new \RuntimeException('Nagad পেমেন্ট সম্পন্ন করা যায়নি।');
        }

        $payment->update(['gateway_transaction_id' => $paymentRefId]);

        return $completeData['callBackUrl'];
    }

    public function verifyWebhook(Request $request, array $credentials): ?array
    {
        $paymentRefId = $request->input('payment_ref_id');
        $orderId = $request->input('order_id');
        if (! $paymentRefId || ! $orderId) {
            return null;
        }

        $response = Http::get($this->baseUrl($credentials)."/api/dfs/verify/payment/{$paymentRefId}");
        $data = $response->json();

        if (! $response->ok() || ($data['status'] ?? null) !== 'Success' || ($data['orderId'] ?? null) !== $orderId) {
            Log::warning('Nagad verify did not confirm success', ['payment_ref_id' => $paymentRefId, 'response' => $data]);

            return null;
        }

        return [
            'reference' => $orderId,
            'gateway_transaction_id' => $data['issuerPaymentRefId'] ?? $paymentRefId,
            'amount' => (float) ($data['amount'] ?? 0),
            'status' => 'success',
        ];
    }
}
