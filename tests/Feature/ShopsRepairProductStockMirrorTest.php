<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsRepairProductStockMirrorTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_recomputes_a_drifted_mirror_from_its_variants(): void
    {
        [$shop] = $this->createShopWithOwner();
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'L-10011', 'cost' => 100, 'price' => 200, 'stock' => 18]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '40', 'color' => 'BLACK', 'stock' => 9]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '41', 'color' => 'BLACK', 'stock' => 8]);

        $this->artisan('shops:repair-product-stock-mirror', ['--shop' => $shop->id])
            ->expectsOutputToContain('L-10011: 18.000 → 17')
            ->assertSuccessful();

        $this->assertEquals(17, (float) $p->fresh()->stock);
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner();
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'L-10011', 'cost' => 100, 'price' => 200, 'stock' => 18]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '40', 'color' => 'BLACK', 'stock' => 9]);

        $this->artisan('shops:repair-product-stock-mirror', ['--shop' => $shop->id, '--dry-run' => true])->assertSuccessful();

        $this->assertEquals(18, (float) $p->fresh()->stock);
    }

    public function test_a_plain_product_with_no_variants_is_left_alone(): void
    {
        [$shop] = $this->createShopWithOwner();
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Plain', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->artisan('shops:repair-product-stock-mirror', ['--shop' => $shop->id])
            ->expectsOutputToContain('0টা প্রোডাক্ট')
            ->assertSuccessful();

        $this->assertEquals(5, (float) $p->fresh()->stock);
    }
}
