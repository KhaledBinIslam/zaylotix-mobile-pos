<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Both rates are owner-configurable (see the migration's comment) —
 * loyalty_earn_rate (points per 100tk actually paid) and
 * loyalty_point_value (taka a redeemed point is worth). Redemption is a
 * money-equivalent operation on the customer's own balance, so it gets the
 * same lock-then-recheck-inside-the-transaction treatment as every other
 * balance check this session (PurchaseController, SupplierReturnController).
 */
class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_sale_earns_points_for_the_attached_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_earn_rate' => 2]); // 2 points per 100tk
        $this->grantFeature($shop, 'loyalty_points');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01911111111',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertOk();
        $customer = Customer::where('phone', '01911111111')->first();
        $this->assertSame(2, $customer->loyalty_points); // 100tk * 2/100
        $this->assertSame(2, Sale::first()->points_earned);
    }

    public function test_no_points_earned_when_the_feature_is_not_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_earn_rate' => 2]);
        // deliberately NOT granting 'loyalty_points'
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01911111111',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $this->assertSame(0, Customer::where('phone', '01911111111')->first()->loyalty_points);
    }

    public function test_no_points_earned_without_an_attached_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_earn_rate' => 2]);
        $this->grantFeature($shop, 'loyalty_points');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $this->assertSame(0, Customer::count());
        $this->assertNull(Sale::first()->points_earned);
    }

    public function test_redeeming_points_discounts_the_total_and_decrements_the_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_point_value' => 2]); // 1 point = 2tk
        $this->grantFeature($shop, 'loyalty_points');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Regular', 'phone' => '01922222222', 'loyalty_points' => 10]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01922222222',
            'redeem_points' => 5,
            'payments' => [['method' => 'cash', 'amount' => 90]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertEquals(90.0, (float) $sale->total); // 100 - 5*2
        $this->assertSame(5, $sale->points_redeemed);
        $this->assertSame(5, $customer->fresh()->loyalty_points); // 10 - 5 (earn on this sale is 0 by default rate=1 -> 0 since floor(90/100)=0)
    }

    public function test_cannot_redeem_more_points_than_the_customer_has(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_point_value' => 1]);
        $this->grantFeature($shop, 'loyalty_points');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Regular', 'phone' => '01933333333', 'loyalty_points' => 3]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01933333333',
            'redeem_points' => 10,
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(3, $customer->fresh()->loyalty_points); // unchanged
        $this->assertSame(0, Sale::count());
    }

    public function test_cannot_redeem_points_without_an_attached_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'loyalty_points');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'redeem_points' => 5,
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_cannot_redeem_points_when_the_feature_is_not_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // deliberately NOT granting 'loyalty_points'
        Customer::create(['shop_id' => $shop->id, 'name' => 'Regular', 'phone' => '01944444444', 'loyalty_points' => 10]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_phone' => '01944444444',
            'redeem_points' => 5,
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    /**
     * Regression-style test for the same double-spend race class fixed
     * repeatedly this session — the Laravel test client is sequential, but
     * it still proves the lock-then-recheck logic doesn't let more points
     * be redeemed across two requests than the customer actually has.
     */
    public function test_repeated_redemption_requests_cannot_overdraw_the_point_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['loyalty_point_value' => 1]);
        $this->grantFeature($shop, 'loyalty_points');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Regular', 'phone' => '01955555555', 'loyalty_points' => 5]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);

        $results = [];
        foreach (range(1, 3) as $i) {
            $results[] = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
                'items' => [['product_id' => $product->id, 'qty' => 1]],
                'customer_phone' => '01955555555',
                'redeem_points' => 5,
                'payments' => [['method' => 'cash', 'amount' => 95]],
            ])->getStatusCode();
        }

        $this->assertSame(1, collect($results)->filter(fn ($code) => $code < 400)->count());
        $this->assertSame(2, collect($results)->filter(fn ($code) => $code === 422)->count());
        $this->assertSame(0, $customer->fresh()->loyalty_points); // spent exactly once
    }

    public function test_owner_can_update_the_loyalty_rates(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'loyalty_points');

        $response = $this->actingAs($owner, 'web')->patch('/app/settings/loyalty', [
            'loyalty_earn_rate' => 5,
            'loyalty_point_value' => 2,
        ]);

        $response->assertRedirect();
        $this->assertEquals(5.0, (float) $shop->fresh()->loyalty_earn_rate);
        $this->assertEquals(2.0, (float) $shop->fresh()->loyalty_point_value);
    }
}
