<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 2 — see docs/FIX_PLAN.md. Confirmed bug: the billing popup computed
 * its own total client-side without ever subtracting a coupon's or redeemed
 * loyalty points' discount, so it tendered more than the server's real
 * (lower) total and tripped checkout's "payment exceeds total" guard. This
 * preview endpoint must return exactly the same total checkout() itself
 * would charge for the identical cart, and must never create a real Sale
 * or move any stock.
 */
class BillingPreviewTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_preview_with_a_coupon_matches_what_checkout_would_actually_charge(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'Eid Sale', 'type' => 'coupon', 'code' => 'EID10', 'discount_type' => 'percent', 'discount_value' => 10]);

        $payload = [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'coupon_code' => 'EID10',
        ];

        $preview = $this->actingAs($owner, 'web')->postJson('/app/pos/billing-preview', $payload);
        $preview->assertOk();
        // subtotal 200, 10% coupon = 20 discount, total 180
        $this->assertEquals(200.0, $preview->json('subtotal'));
        $this->assertEquals(20.0, $preview->json('discount'));
        $this->assertEquals(180.0, $preview->json('total'));

        // preview must never create a real sale or touch stock
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $product->fresh()->stock);

        // the real checkout, tendering exactly the previewed total, must succeed
        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload + [
            'payments' => [['method' => 'cash', 'amount' => $preview->json('total')]],
        ]);
        $checkout->assertOk();
        $this->assertEquals(180.0, (float) Sale::first()->total);
    }

    public function test_preview_with_redeemed_loyalty_points_matches_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'loyalty_points');
        $shop->update(['loyalty_point_value' => 1]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'loyalty_points' => 50]);

        $payload = [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01711111111',
            'redeem_points' => 30,
        ];

        $preview = $this->actingAs($owner, 'web')->postJson('/app/pos/billing-preview', $payload);
        $preview->assertOk();
        $this->assertEquals(30.0, $preview->json('discount'));
        $this->assertEquals(70.0, $preview->json('total'));
        $this->assertEquals(50, $customer->fresh()->loyalty_points); // rolled back, not actually spent

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload + [
            'payments' => [['method' => 'cash', 'amount' => 70]],
        ]);
        $checkout->assertOk();
        $this->assertEquals(20, $customer->fresh()->loyalty_points); // actually spent this time
    }

    public function test_preview_never_creates_a_sale_even_when_the_cart_is_unpriceable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 1]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/billing-preview', [
            'items' => [['product_id' => $product->id, 'qty' => 5]], // more than in stock
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(1, $product->fresh()->stock);
    }
}
