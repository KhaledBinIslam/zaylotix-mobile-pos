<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Two promotion styles: BOGO/combo (auto-applied at checkout, no code —
 * see PromotionEngine::computeAutoLineDiscounts) and coupon codes
 * (typed in at checkout, see PromotionEngine::resolveCoupon). Checkout
 * itself is covered elsewhere (CheckoutTransactionTest); these tests focus
 * on what the promotion layer adds on top of that already-tested flow.
 */
class PromotionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    // ---- BOGO/combo (auto-applied) ----

    public function test_buy_2_get_1_free_of_the_same_product_discounts_one_unit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'B2G1', 'type' => 'bogo', 'buy_product_id' => $product->id, 'buy_qty' => 2, 'get_product_id' => $product->id, 'get_qty' => 1, 'get_discount_percent' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 40]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertEquals(40.0, (float) $sale->total); // 3*20 - 1*20 (one free unit)
        $this->assertEquals(47, $product->fresh()->stock); // all 3 units still leave stock — the "free" one is still physically handed over
    }

    public function test_combo_gives_a_different_product_free_when_the_trigger_product_is_bought(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $burger = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $drink = Product::create(['shop_id' => $shop->id, 'name' => 'Drink', 'cost' => 10, 'price' => 30, 'stock' => 20]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'Combo', 'type' => 'bogo', 'buy_product_id' => $burger->id, 'buy_qty' => 1, 'get_product_id' => $drink->id, 'get_qty' => 1, 'get_discount_percent' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $burger->id, 'qty' => 1],
                ['product_id' => $drink->id, 'qty' => 1],
            ],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertOk();
        $this->assertEquals(100.0, (float) Sale::first()->total); // 100 + 30 - 30 (drink free)
    }

    public function test_bogo_does_not_trigger_below_the_buy_quantity(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'B2G1', 'type' => 'bogo', 'buy_product_id' => $product->id, 'buy_qty' => 2, 'get_product_id' => $product->id, 'get_qty' => 1, 'get_discount_percent' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertOk();
        $this->assertEquals(20.0, (float) Sale::first()->total); // no discount, only 1 bought
    }

    public function test_combo_reward_is_capped_by_the_reward_products_own_quantity_in_cart(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $burger = Product::create(['shop_id' => $shop->id, 'name' => 'Burger', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $drink = Product::create(['shop_id' => $shop->id, 'name' => 'Drink', 'cost' => 10, 'price' => 30, 'stock' => 20]);
        // buy 1 burger get 2 drinks free, but cart only has 1 drink
        Promotion::create(['shop_id' => $shop->id, 'name' => 'Combo', 'type' => 'bogo', 'buy_product_id' => $burger->id, 'buy_qty' => 1, 'get_product_id' => $drink->id, 'get_qty' => 2, 'get_discount_percent' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $burger->id, 'qty' => 1],
                ['product_id' => $drink->id, 'qty' => 1],
            ],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertOk();
        $this->assertEquals(100.0, (float) Sale::first()->total); // only the 1 drink actually in cart goes free
    }

    public function test_inactive_bogo_promotion_does_not_apply(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'B2G1', 'type' => 'bogo', 'active' => false, 'buy_product_id' => $product->id, 'buy_qty' => 2, 'get_product_id' => $product->id, 'get_qty' => 1, 'get_discount_percent' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ]);

        $this->assertEquals(60.0, (float) Sale::first()->total); // no discount, promotion is off
    }

    // ---- coupon codes ----

    public function test_a_valid_percent_coupon_discounts_the_subtotal(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $promo = Promotion::create(['shop_id' => $shop->id, 'name' => 'Eid Sale', 'type' => 'coupon', 'code' => 'EID10', 'discount_type' => 'percent', 'discount_value' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'coupon_code' => 'eid10', // case-insensitive typing shouldn't matter... actually code matching is exact; test exact case below
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ]);

        // exact-case mismatch is rejected — documents current exact-match behavior
        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'coupon_code' => 'EID10',
            'payments' => [['method' => 'cash', 'amount' => 180]],
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertEquals(180.0, (float) $sale->total); // 200 - 10%
        $this->assertSame('EID10', $sale->coupon_code);
        $this->assertSame(1, $promo->fresh()->used_count);
    }

    public function test_a_fixed_coupon_discounts_a_flat_amount(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'Flat50', 'type' => 'coupon', 'code' => 'FLAT50', 'discount_type' => 'fixed', 'discount_value' => 50]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'coupon_code' => 'FLAT50',
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ]);

        $response->assertOk();
        $this->assertEquals(50.0, (float) Sale::first()->total); // 100 - 50
    }

    public function test_coupon_below_minimum_purchase_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'Big10', 'type' => 'coupon', 'code' => 'BIG10', 'discount_type' => 'percent', 'discount_value' => 10, 'min_purchase' => 500]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'coupon_code' => 'BIG10',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_exhausted_coupon_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'OneTime', 'type' => 'coupon', 'code' => 'ONCE', 'discount_type' => 'fixed', 'discount_value' => 10, 'usage_limit' => 1, 'used_count' => 1]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'coupon_code' => 'ONCE',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
    }

    public function test_an_expired_coupon_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Promotion::create(['shop_id' => $shop->id, 'name' => 'OldSale', 'type' => 'coupon', 'code' => 'OLD', 'discount_type' => 'fixed', 'discount_value' => 10, 'expires_at' => now()->subDay()->toDateString()]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'coupon_code' => 'OLD',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
    }

    public function test_a_coupon_belonging_to_another_shop_does_not_apply_here(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'promotions');
        [$shopB] = $this->createShopWithOwner();
        Promotion::create(['shop_id' => $shopB->id, 'name' => 'ShopB Coupon', 'type' => 'coupon', 'code' => 'SHARED', 'discount_type' => 'fixed', 'discount_value' => 50]);
        $product = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $response = $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'coupon_code' => 'SHARED',
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    // ---- management CRUD ----

    public function test_owner_can_create_a_bogo_promotion(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/promotions', [
            'name' => 'B2G1', 'type' => 'bogo', 'buy_product_id' => $product->id, 'buy_qty' => 2, 'get_qty' => 1,
        ]);

        $response->assertRedirect();
        $promo = Promotion::first();
        $this->assertSame('bogo', $promo->type);
        $this->assertSame($product->id, $promo->get_product_id); // defaulted to the buy product
        $this->assertEquals(100.0, (float) $promo->get_discount_percent); // defaulted to fully free
    }

    public function test_owner_can_create_a_coupon(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');

        $response = $this->actingAs($owner, 'web')->post('/app/promotions', [
            'name' => 'Eid Sale', 'type' => 'coupon', 'code' => 'EID10', 'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Promotion::where('code', 'EID10')->count());
    }

    public function test_a_percent_coupon_over_100_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');

        $response = $this->actingAs($owner, 'web')->post('/app/promotions', [
            'name' => 'Too Big', 'type' => 'coupon', 'code' => 'BIG', 'discount_type' => 'percent', 'discount_value' => 150,
        ]);

        $response->assertSessionHasErrors('discount_value');
    }

    public function test_coupon_codes_must_be_unique_within_a_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        Promotion::create(['shop_id' => $shop->id, 'name' => 'First', 'type' => 'coupon', 'code' => 'DUP', 'discount_type' => 'fixed', 'discount_value' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/promotions', [
            'name' => 'Second', 'type' => 'coupon', 'code' => 'DUP', 'discount_type' => 'fixed', 'discount_value' => 20,
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_the_same_coupon_code_is_allowed_across_different_shops(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'promotions');
        Promotion::create(['shop_id' => $shopB->id, 'name' => 'Other Shop', 'type' => 'coupon', 'code' => 'SAME', 'discount_type' => 'fixed', 'discount_value' => 10]);

        $response = $this->actingAs($ownerA, 'web')->post('/app/promotions', [
            'name' => 'Mine', 'type' => 'coupon', 'code' => 'SAME', 'discount_type' => 'fixed', 'discount_value' => 20,
        ]);

        $response->assertRedirect();
        $this->assertSame(2, Promotion::withoutGlobalScopes()->where('code', 'SAME')->count());
    }

    public function test_owner_can_delete_a_promotion(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'promotions');
        $promo = Promotion::create(['shop_id' => $shop->id, 'name' => 'Gone', 'type' => 'coupon', 'code' => 'GONE', 'discount_type' => 'fixed', 'discount_value' => 10]);

        $this->actingAs($owner, 'web')->delete("/app/promotions/{$promo->id}")->assertRedirect();

        $this->assertSame(0, Promotion::count());
    }
}
