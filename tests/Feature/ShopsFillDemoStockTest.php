<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsFillDemoStockTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_fills_every_variant_and_plain_product_of_a_demo_shop(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'demo']);
        $plain = Product::create(['shop_id' => $shop->id, 'name' => 'Plain', 'cost' => 10, 'price' => 20, 'stock' => 0]);
        $withVariants = Product::create(['shop_id' => $shop->id, 'name' => 'P-10001', 'cost' => 100, 'price' => 200, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $withVariants->id, 'size' => '32', 'color' => 'BLACK', 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $withVariants->id, 'size' => '34', 'color' => 'BLACK', 'stock' => 1]);

        $this->artisan('shops:fill-demo-stock', ['--shop' => $shop->id, '--qty' => 15])->assertSuccessful();

        $this->assertEquals(15, $plain->fresh()->stock);
        $this->assertEquals(30, $withVariants->fresh()->stock);
        $this->assertEquals([15.0, 15.0], ProductVariant::withoutGlobalScopes()->where('product_id', $withVariants->id)->pluck('stock')->map(fn ($s) => (float) $s)->all());
    }

    public function test_refuses_a_paid_shop_without_force(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'paid']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Real', 'cost' => 10, 'price' => 20, 'stock' => 3]);

        $this->artisan('shops:fill-demo-stock', ['--shop' => $shop->id])->assertFailed();
        $this->assertEquals(3, $p->fresh()->stock);
    }

    public function test_force_allows_a_paid_shop(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'paid']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Real', 'cost' => 10, 'price' => 20, 'stock' => 3]);

        $this->artisan('shops:fill-demo-stock', ['--shop' => $shop->id, '--force' => true])->assertSuccessful();
        $this->assertEquals(20, $p->fresh()->stock);
    }
}
