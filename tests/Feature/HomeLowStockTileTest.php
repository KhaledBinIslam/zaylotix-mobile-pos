<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Home's "Low Stock" tile used to silently fold out-of-stock products into
 * its own count (lowStockCount = $lowStock + $outOfStock) even though
 * there's a separate "Out of Stock" tile for exactly those products. Now
 * that tapping it opens Stock filtered to stock_status=low (which never
 * includes an out-of-stock product), the tile's own number has to mean the
 * same "strictly low, not out" thing - see Product::scopeLowStock().
 */
class HomeLowStockTileTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_low_stock_tile_excludes_out_of_stock_products(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['onboarded_at' => now()]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Low Stock Item', 'cost' => 1, 'price' => 2, 'stock' => 3]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Empty Item', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('lowStockCount', 1)
            ->where('outOfStockCount', 1)
        );
    }
}
