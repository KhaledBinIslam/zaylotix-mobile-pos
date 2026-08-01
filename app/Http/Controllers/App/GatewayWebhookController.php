<?php

namespace App\Http\Controllers\App;

use App\Models\GatewayPayment;
use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayCredential;
use App\Support\Gateways\GatewayManager;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public, unauthenticated endpoint every gateway calls (SSLCommerz's IPN
 * server-to-server, or a browser redirect for all three) — the ONLY thing
 * that ever creates the real Sale for a gateway checkout. Never trusts the
 * request body on its own; every driver's verifyWebhook() independently
 * confirms the payment against the gateway's own servers first (see each
 * driver class).
 *
 * IMPORTANT tenancy note: this route has no session/auth at all, so
 * App\Models\Concerns\BelongsToTenant's global scope (which every tenant
 * model, including GatewayPayment itself, relies on Tenancy::id() for) has
 * nothing to resolve a shop from yet. process() deliberately bypasses that
 * scope for the very first lookup, then calls Tenancy::set() immediately so
 * every later query (including inside PosController::performCheckout)
 * resolves against the correct shop — the same pattern the artisan commands
 * in app/Console/Commands that iterate every shop already use.
 *
 * Idempotent by construction: both the browser callback and an async IPN
 * can legitimately hit this for the same payment, and a gateway is free to
 * retry a webhook it thinks failed — the `status !== 'pending'` guard
 * (checked again after acquiring the row lock, not just before) means a
 * second confirmation for an already-completed payment is a no-op, never a
 * second Sale.
 */
class GatewayWebhookController extends Controller
{
    /** Server-to-server IPN — must always get a plain HTTP status/JSON body back, never a redirect. */
    public function webhook(Request $request, string $provider)
    {
        $result = $this->process($request, $provider);

        return response()->json($result['body'], $result['status']);
    }

    /** The customer's own browser landing back from the gateway — sends them back to the POS page with a query flag the frontend polls against, since the async IPN and this callback don't always arrive in a guaranteed order. */
    public function callback(Request $request, string $provider)
    {
        $result = $this->process($request, $provider);

        return redirect()->route('app.pos', [
            'gateway_reference' => $result['reference'],
            'gateway_status' => $result['body']['ok'] ? 'success' : 'failed',
        ]);
    }

    private function process(Request $request, string $provider): array
    {
        if (! in_array($provider, GatewayManager::providers(), true)) {
            abort(404);
        }

        $reference = $request->input('tran_id') // sslcommerz
            ?? $request->input('merchantInvoiceNumber') // bkash (present on some callback variants)
            ?? $request->input('order_id'); // nagad

        // bKash's callback only reliably carries paymentID, not our own
        // reference, until Execute is called — so for bkash we look the
        // pending payment up by gateway_transaction_id instead, set at
        // initiate() time (see BkashDriver::initiate).
        $payment = $reference
            ? GatewayPayment::withoutGlobalScopes()->where('provider', $provider)->where('reference', $reference)->first()
            : GatewayPayment::withoutGlobalScopes()->where('provider', $provider)
                ->where('gateway_transaction_id', $request->input('paymentID'))
                ->first();

        if (! $payment) {
            Log::warning('Gateway webhook for unknown payment', ['provider' => $provider, 'query' => $request->query(), 'body' => $request->post()]);

            return ['body' => ['ok' => false], 'status' => 404, 'reference' => $reference];
        }

        // resolved before anything else — every query from here on
        // (including inside PosController::performCheckout) needs this to
        // land on the right shop, since this request never went through
        // the normal EnsureShopUser/session-bound tenancy path
        Tenancy::set($payment->shop_id);

        if ($payment->status !== 'pending') {
            // already handled (duplicate IPN + callback, or a gateway retry)
            return ['body' => ['ok' => $payment->status === 'completed', 'status' => $payment->status], 'status' => 200, 'reference' => $payment->reference];
        }

        $credential = PaymentGatewayCredential::where('shop_id', $payment->shop_id)->where('provider', $provider)->first();
        if (! $credential) {
            Log::error('Gateway webhook arrived but credential no longer exists', ['payment_id' => $payment->id]);

            return ['body' => ['ok' => false], 'status' => 422, 'reference' => $payment->reference];
        }

        $verified = GatewayManager::driver($provider)->verifyWebhook($request, $credential->credentials);

        if (! $verified || $verified['reference'] !== $payment->reference || abs($verified['amount'] - (float) $payment->amount) > 0.01) {
            Log::warning('Gateway webhook failed verification or amount mismatch', ['payment_id' => $payment->id, 'verified' => $verified]);
            $payment->update(['status' => 'failed']);

            return ['body' => ['ok' => false], 'status' => 422, 'reference' => $payment->reference];
        }

        // re-lock and re-check status INSIDE the transaction — the same
        // "check before AND after locking" discipline every other
        // money-moving flow in this app uses, so two near-simultaneous
        // calls for the same payment can never both pass the earlier
        // pending-check and create two Sales
        try {
            DB::transaction(function () use ($payment, $verified) {
                $locked = GatewayPayment::whereKey($payment->id)->lockForUpdate()->first();
                if ($locked->status !== 'pending') {
                    return;
                }

                $sale = PosController::performCheckout(
                    $locked->checkout_payload,
                    $locked->shop_id,
                    $locked->user_id,
                    forcedPayments: [['method' => 'gateway', 'amount' => (float) $locked->amount]],
                );

                $locked->update([
                    'status' => 'completed',
                    'gateway_transaction_id' => $verified['gateway_transaction_id'] ?? $locked->gateway_transaction_id,
                    'sale_id' => $sale->id,
                ]);
            });
        } catch (\Throwable $e) {
            // the money was already confirmed real by the gateway at this
            // point — a failure here (e.g. stock changed between initiate
            // and confirm) means a customer paid but no sale/invoice exists.
            // This can never be silently swallowed: log loudly so an admin
            // manually reconciles/refunds, and leave the payment as
            // 'pending' rather than 'failed' so it's obviously not resolved.
            Log::critical('Gateway payment confirmed by provider but performCheckout failed — needs manual reconciliation/refund', [
                'payment_id' => $payment->id, 'shop_id' => $payment->shop_id, 'amount' => (string) $payment->amount,
                'provider' => $provider, 'error' => $e->getMessage(),
            ]);

            return ['body' => ['ok' => false, 'message' => 'checkout_failed_after_payment'], 'status' => 500, 'reference' => $payment->reference];
        }

        return ['body' => ['ok' => true], 'status' => 200, 'reference' => $payment->reference];
    }
}
