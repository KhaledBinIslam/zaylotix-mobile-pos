<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class WholesaleTiersTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function grantWholesaleTiers($shop): void
    {
        $this->grantFeature($shop, 'wholesale_pricing');
        $this->grantFeature($shop, 'wholesale_tiers');
    }

    public function test_checkout_picks_the_richest_tier_the_qty_clears(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'stock' => 1000]);
        ProductPriceTier::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'min_qty' => 10, 'price' => 17]);
        ProductPriceTier::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'min_qty' => 50, 'price' => 15]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 60]],
            'payments' => [['method' => 'cash', 'amount' => 900]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(900.0, (float) Sale::first()->total); // 60 * 15 (the 50+ tier)
    }

    public function test_checkout_falls_back_to_flat_wholesale_price_below_the_lowest_tier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'stock' => 1000]);
        ProductPriceTier::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'min_qty' => 10, 'price' => 17]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 90]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(90.0, (float) Sale::first()->total); // 5 * 18 (flat wholesale_price, no tier cleared)
    }

    public function test_a_product_with_no_tiers_behaves_exactly_like_the_flat_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop); // feature on, but this product has no tier rows
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(150.0, (float) Sale::first()->total);
    }

    public function test_tiers_are_ignored_without_the_wholesale_tiers_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing'); // base feature only, not wholesale_tiers
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'stock' => 1000]);
        ProductPriceTier::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'min_qty' => 10, 'price' => 15]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 60]],
            'payments' => [['method' => 'cash', 'amount' => 1080]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(1080.0, (float) Sale::first()->total); // 60 * 18 (flat price), tier never consulted
    }

    public function test_wholesale_moq_rejects_a_line_below_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'wholesale_moq' => 20, 'stock' => 1000]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 90]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_wholesale_moq_allows_a_line_at_or_above_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'wholesale_moq' => 20, 'stock' => 1000]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 20]],
            'payments' => [['method' => 'cash', 'amount' => 360]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
    }

    public function test_moq_is_not_enforced_on_a_retail_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_moq' => 20, 'stock' => 1000]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertOk();
    }

    public function test_moq_not_set_means_none_is_enforced(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shop);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'stock' => 1000]); // no moq

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 18]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
    }

    public function test_price_tiers_are_scoped_to_their_own_shop(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantWholesaleTiers($shopA);
        $this->grantWholesaleTiers($shopB);

        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'stock' => 1000]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 18, 'stock' => 1000]);
        ProductPriceTier::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'min_qty' => 10, 'price' => 5]);

        $response = $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productA->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 180]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(180.0, (float) Sale::first()->total); // shopB's tier never applies to shopA's product
    }
}
