<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Real bug found while building dead-stock clearance (Phase 3 feature 3,
 * see docs/FIX_PLAN.md), confirmed with Khaled it was never intentional:
 * `products.discount_price` has existed for a while (settable in
 * Stock/Index.vue, shown on barcode labels and exports) but
 * PosController::performCheckout() never actually read it — a cashier
 * always charged full price regardless of what was set.
 */
class DiscountPriceCheckoutTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_checkout_charges_the_discount_price_when_set(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Old Stock Shirt', 'cost' => 200, 'price' => 500, 'discount_price' => 300, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 300]],
        ]);

        $response->assertOk();
        $this->assertEquals(300.0, (float) Sale::first()->total);
    }

    public function test_checkout_charges_full_price_when_discount_price_is_not_set(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Normal Item', 'cost' => 200, 'price' => 500, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
        ]);

        $response->assertOk();
        $this->assertEquals(500.0, (float) Sale::first()->total);
    }

    public function test_wholesale_price_wins_over_discount_price_on_a_wholesale_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 100, 'price' => 500, 'discount_price' => 300, 'wholesale_price' => 250, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 250]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(250.0, (float) Sale::first()->total); // wholesale_price, not discount_price
    }

    public function test_pack_unit_price_is_unaffected_by_discount_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Strip Item', 'cost' => 100, 'price' => 500, 'discount_price' => 300, 'stock' => 10]);
        $unit = \App\Models\Unit::create(['shop_id' => $shop->id, 'name' => 'Box']);
        $productUnit = \App\Models\ProductUnit::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'unit_id' => $unit->id, 'factor' => 1, 'price' => 450]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'product_unit_id' => $productUnit->id]],
            'payments' => [['method' => 'cash', 'amount' => 450]],
        ]);

        $response->assertOk();
        $this->assertEquals(450.0, (float) Sale::first()->total); // the pack unit's own price, not discount_price
    }

    public function test_billing_preview_matches_what_checkout_actually_charges_with_a_discount_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Old Stock Shirt', 'cost' => 200, 'price' => 500, 'discount_price' => 300, 'stock' => 10]);

        $preview = $this->actingAs($owner, 'web')->postJson('/app/pos/billing-preview', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->json();

        $this->assertEquals(300.0, (float) $preview['total']);
    }
}
