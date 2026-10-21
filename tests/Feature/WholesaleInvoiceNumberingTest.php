<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 4 "full Wholesale system", part C — separate wholesale invoice
 * numbering (see docs/FIX_PLAN.md's design note). Only a wholesale sale
 * under the fuller wholesale_tiers feature gets the 'WS-' series; a
 * retail sale, and a wholesale sale on a shop with only the base
 * wholesale_pricing feature, both keep the exact 'INV-' series unchanged.
 */
class WholesaleInvoiceNumberingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_wholesale_sale_under_wholesale_tiers_gets_a_ws_invoice_number(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $this->grantFeature($shop, 'wholesale_tiers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ])->assertOk();

        $this->assertStringStartsWith('WS-', Sale::first()->invoice_no);
    }

    public function test_a_retail_sale_keeps_the_inv_series_even_with_wholesale_tiers_on(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $this->grantFeature($shop, 'wholesale_tiers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $this->assertStringStartsWith('INV-', Sale::first()->invoice_no);
    }

    public function test_a_wholesale_sale_with_only_the_base_feature_keeps_the_inv_series(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing'); // wholesale_tiers NOT granted
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ])->assertOk();

        $this->assertStringStartsWith('INV-', Sale::first()->invoice_no);
    }

    public function test_ws_and_inv_counters_are_independent_and_never_collide(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $this->grantFeature($shop, 'wholesale_tiers');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 1000]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ])->assertOk();

        $invoiceNumbers = Sale::orderBy('id')->pluck('invoice_no');
        $this->assertCount(3, $invoiceNumbers->unique());
        $this->assertSame(['WS-1001', 'INV-1001', 'WS-1002'], $invoiceNumbers->all());
    }
}
