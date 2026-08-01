<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class WholesalePricingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_checkout_uses_wholesale_price_when_sale_type_is_wholesale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $sale = Sale::first();
        $this->assertSame('wholesale', $sale->sale_type);
        $this->assertEquals(150.0, (float) $sale->total); // 10 * 15, not 10 * 20
    }

    public function test_checkout_falls_back_to_retail_price_when_no_wholesale_price_is_set(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]); // no wholesale_price

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(200.0, (float) Sale::first()->total); // still 10 * 20
    }

    public function test_wholesale_price_is_ignored_without_the_wholesale_pricing_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 100]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
            'sale_type' => 'wholesale',
        ]);

        $response->assertOk();
        $this->assertEquals(200.0, (float) Sale::first()->total); // wholesale ignored, sold at retail
    }

    public function test_default_sale_type_is_retail(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $this->assertSame('retail', Sale::first()->sale_type);
    }

    public function test_reports_page_shows_the_retail_wholesale_breakdown(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $this->grantFeature($shop, 'reports');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'wholesale_price' => 15, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
            'sale_type' => 'wholesale',
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('salesByType.wholesale.total', 150)
            ->where('salesByType.retail.total', 20)
        );
    }
}
