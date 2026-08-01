<?php

namespace App\Support\Gateways;

use App\Models\GatewayPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SSLCommerz's hosted "Easy Checkout" flow — a shop's own store_id/
 * store_passwd (from their SSLCommerz merchant panel), Session API to
 * start a payment, and the IPN (Instant Payment Notification) + a
 * mandatory server-to-server Order Validation call to actually trust it.
 *
 * SSLCommerz's IPN POST is NOT itself cryptographically signed — anyone
 * could POST a fake "VALID" IPN to our webhook URL. The only real trust
 * boundary is calling SSLCommerz's own Validation API back with the
 * val_id it gave us and checking ITS answer — verifyWebhook() below does
 * exactly that and returns null (reject) if that server-to-server call
 * doesn't independently confirm the transaction.
 */
class SslcommerzDriver implements GatewayDriver
{
    public function requiredCredentialFields(): array
    {
        return ['store_id', 'store_passwd', 'sandbox'];
    }

    private function baseUrl(array $credentials): string
    {
        return ($credentials['sandbox'] ?? true)
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';
    }

    public function initiate(GatewayPayment $payment, array $credentials): string
    {
        $response = Http::asForm()->post($this->baseUrl($credentials).'/gwprocess/v4/api.php', [
            'store_id' => $credentials['store_id'],
            'store_passwd' => $credentials['store_passwd'],
            'total_amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => 'BDT',
            'tran_id' => $payment->reference,
            'success_url' => route('gateway.callback', ['provider' => 'sslcommerz']),
            'fail_url' => route('gateway.callback', ['provider' => 'sslcommerz']),
            'cancel_url' => route('gateway.callback', ['provider' => 'sslcommerz']),
            'ipn_url' => route('gateway.webhook', ['provider' => 'sslcommerz']),
            // SSLCommerz rejects the session request outright without these,
            // even for a simple in-person POS sale with no real shipping step
            'cus_name' => 'Customer', 'cus_email' => 'customer@example.com',
            'cus_add1' => 'N/A', 'cus_city' => 'Dhaka', 'cus_country' => 'Bangladesh', 'cus_phone' => 'N/A',
            'shipping_method' => 'NO', 'product_name' => 'POS Sale', 'product_category' => 'General', 'product_profile' => 'general',
        ]);

        $data = $response->json();
        if (! ($data['status'] ?? null) === 'SUCCESS' && empty($data['GatewayPageURL'])) {
            Log::error('SSLCommerz session init failed', ['response' => $data]);
            throw new \RuntimeException('SSLCommerz সেশন শুরু করা যায়নি।');
        }

        return $data['GatewayPageURL'];
    }

    public function verifyWebhook(Request $request, array $credentials): ?array
    {
        $valId = $request->input('val_id');
        $tranId = $request->input('tran_id');
        if (! $valId || ! $tranId) {
            return null;
        }

        // the untrusted POST body is never trusted directly — this
        // server-to-server GET is the actual source of truth
        $response = Http::get($this->baseUrl($credentials).'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => $credentials['store_id'],
            'store_passwd' => $credentials['store_passwd'],
            'format' => 'json',
        ]);

        $data = $response->json();
        if (! $response->ok() || ! in_array($data['status'] ?? null, ['VALID', 'VALIDATED'], true)) {
            Log::warning('SSLCommerz IPN failed independent validation', ['tran_id' => $tranId, 'response' => $data]);

            return null;
        }

        if ($data['tran_id'] !== $tranId) {
            return null;
        }

        return [
            'reference' => $tranId,
            'gateway_transaction_id' => $data['bank_tran_id'] ?? $valId,
            'amount' => (float) $data['amount'],
            'status' => 'success',
        ];
    }
}
