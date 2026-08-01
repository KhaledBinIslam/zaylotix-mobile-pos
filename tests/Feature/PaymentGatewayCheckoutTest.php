<?php

namespace Tests\Feature;

use App\Models\GatewayPayment;
use App\Models\PaymentGatewayCredential;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * End-to-end gateway checkout flow, using SSLCommerz (the simplest of the
 * three drivers) with Http::fake() standing in for the real gateway — no
 * live sandbox credentials exist for this test run, but the important
 * thing being verified isn't "does SSLCommerz's actual API behave this
 * way", it's "does OUR code react correctly to a valid/invalid/duplicate
 * response shaped like theirs" — the same reasoning that makes mocking a
 * third-party HTTP dependency the right call here rather than a red flag.
 */
class PaymentGatewayCheckoutTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function connectSslcommerz($shop): void
    {
        PaymentGatewayCredential::create([
            'shop_id' => $shop->id, 'provider' => 'sslcommerz',
            'credentials' => ['store_id' => 'test_store', 'store_passwd' => 'test_pass', 'sandbox' => true],
            'is_active' => true,
        ]);
    }

    public function test_initiate_is_rejected_when_no_gateway_is_connected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/gateway/sslcommerz/initiate', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, GatewayPayment::count());
    }

    public function test_initiate_prices_the_cart_and_returns_a_redirect_url_without_creating_a_sale_yet(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->connectSslcommerz($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        Http::fake([
            '*/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/abc123'], 200),
        ]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/gateway/sslcommerz/initiate', [
            'items' => [['product_id' => $product->id, 'qty' => 2]], // 2 * 20 = 40
        ]);

        $response->assertOk();
        $this->assertSame('https://sandbox.sslcommerz.com/pay/abc123', $response->json('redirect_url'));

        $this->assertSame(1, GatewayPayment::count());
        $payment = GatewayPayment::first();
        $this->assertSame('pending', $payment->status);
        $this->assertEquals(40.0, (float) $payment->amount);
        $this->assertEquals(10, (float) $product->fresh()->stock); // untouched — no sale yet
        $this->assertSame(0, Sale::count());
    }

    public function test_webhook_confirms_payment_and_creates_the_real_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->connectSslcommerz($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        Http::fake([
            '*/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/abc123'], 200),
        ]);
        $initiateResponse = $this->actingAs($owner, 'web')->postJson('/app/pos/gateway/sslcommerz/initiate', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ]);
        $reference = $initiateResponse->json('reference');

        Http::fake([
            '*/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID', 'tran_id' => $reference, 'amount' => '40.00', 'bank_tran_id' => 'BANK123',
            ], 200),
        ]);

        $webhookResponse = $this->postJson('/payment-gateway/sslcommerz/webhook', [
            'tran_id' => $reference, 'val_id' => 'val_abc',
        ]);

        $webhookResponse->assertOk();
        $payment = GatewayPayment::withoutGlobalScopes()->where('reference', $reference)->first();
        $this->assertSame('completed', $payment->status);
        $this->assertNotNull($payment->sale_id);

        $this->assertSame(1, Sale::count());
        $sale = Sale::first();
        $this->assertEquals(40.0, (float) $sale->total);
        $this->assertSame('gateway', $sale->payment_mode);
        $this->assertEquals(8, (float) $product->fresh()->stock); // 10 - 2, only now decremented
    }

    public function test_webhook_is_idempotent_and_never_creates_a_second_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->connectSslcommerz($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        Http::fake(['*/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://x/pay'], 200)]);
        $reference = $this->actingAs($owner, 'web')->postJson('/app/pos/gateway/sslcommerz/initiate', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->json('reference');

        Http::fake(['*/validator/api/validationserverAPI.php*' => Http::response([
            'status' => 'VALID', 'tran_id' => $reference, 'amount' => '20.00', 'bank_tran_id' => 'BANK123',
        ], 200)]);

        $this->postJson('/payment-gateway/sslcommerz/webhook', ['tran_id' => $reference, 'val_id' => 'val_abc'])->assertOk();
        $this->postJson('/payment-gateway/sslcommerz/webhook', ['tran_id' => $reference, 'val_id' => 'val_abc'])->assertOk();

        $this->assertSame(1, Sale::count());
        $this->assertEquals(9, (float) $product->fresh()->stock); // decremented exactly once
    }

    public function test_webhook_rejects_when_gateway_validation_reports_invalid(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->connectSslcommerz($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        Http::fake(['*/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://x/pay'], 200)]);
        $reference = $this->actingAs($owner, 'web')->postJson('/app/pos/gateway/sslcommerz/initiate', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->json('reference');

        Http::fake(['*/validator/api/validationserverAPI.php*' => Http::response(['status' => 'FAILED'], 200)]);

        $response = $this->postJson('/payment-gateway/sslcommerz/webhook', ['tran_id' => $reference, 'val_id' => 'val_abc']);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertSame('failed', GatewayPayment::withoutGlobalScopes()->where('reference', $reference)->first()->status);
        $this->assertEquals(10, (float) $product->fresh()->stock);
    }

    public function test_webhook_rejects_an_amount_that_does_not_match_the_priced_cart(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->connectSslcommerz($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        Http::fake(['*/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://x/pay'], 200)]);
        $reference = $this->actingAs($owner, 'web')->postJson('/app/pos/gateway/sslcommerz/initiate', [
            'items' => [['product_id' => $product->id, 'qty' => 1]], // priced at 20
        ])->json('reference');

        // gateway claims only 5 taka was actually paid — must not be trusted as a full match
        Http::fake(['*/validator/api/validationserverAPI.php*' => Http::response([
            'status' => 'VALID', 'tran_id' => $reference, 'amount' => '5.00', 'bank_tran_id' => 'BANK123',
        ], 200)]);

        $response = $this->postJson('/payment-gateway/sslcommerz/webhook', ['tran_id' => $reference, 'val_id' => 'val_abc']);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_webhook_for_an_unknown_reference_is_rejected(): void
    {
        $response = $this->postJson('/payment-gateway/sslcommerz/webhook', ['tran_id' => 'no-such-reference', 'val_id' => 'x']);

        $response->assertStatus(404);
        $this->assertSame(0, Sale::count());
    }

    public function test_manual_checkout_is_completely_unaffected_when_a_gateway_is_also_connected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->connectSslcommerz($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ]);

        $response->assertOk();
        $this->assertSame(1, Sale::count());
        $this->assertSame('cash', Sale::first()->payment_mode);
        $this->assertEquals(7, (float) $product->fresh()->stock);
    }
}
