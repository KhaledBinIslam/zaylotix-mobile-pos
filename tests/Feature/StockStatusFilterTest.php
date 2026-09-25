<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Home's low-stock/out-of-stock tiles (and Stock's own copies of the same
 * tiles) showed a count but tapping did nothing - this is the filter that
 * makes them actually open Stock pre-filtered to those exact products,
 * using the same thresholds the count itself was computed with.
 */
class StockStatusFilterTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_low_filter_matches_the_same_threshold_as_the_count(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Low Stock Item', 'cost' => 1, 'price' => 2, 'stock' => 3]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Plenty Stock Item', 'cost' => 1, 'price' => 2, 'stock' => 50]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Empty Item', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $response = $this->actingAs($owner, 'web')->get('/app/stock?stock_status=low');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Low Stock Item')
        );
    }

    public function test_out_filter_only_matches_zero_or_negative_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Empty Item', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Low Stock Item', 'cost' => 1, 'price' => 2, 'stock' => 3]);

        $response = $this->actingAs($owner, 'web')->get('/app/stock?stock_status=out');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Empty Item')
        );
    }

    public function test_a_weighed_products_low_threshold_is_narrower(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // 6 units would be "low" for a piece-counted product but is well
        // above a weighed product's own (tighter) 1-unit low threshold
        Product::create(['shop_id' => $shop->id, 'name' => 'Weighed Plenty', 'cost' => 1, 'price' => 2, 'stock' => 6, 'sold_by_weight' => true, 'weight_unit' => 'kg']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Weighed Low', 'cost' => 1, 'price' => 2, 'stock' => 0.5, 'sold_by_weight' => true, 'weight_unit' => 'kg']);

        $response = $this->actingAs($owner, 'web')->get('/app/stock?stock_status=low');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Weighed Low')
        );
    }

    public function test_untracked_stock_mode_products_are_excluded_from_either_filter(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // an always-available restaurant dish sits at stock=0 forever by
        // design (see Product::STOCK_MODE_*) - it must never show up as
        // "out of stock" just because its stock column happens to be 0
        Product::create(['shop_id' => $shop->id, 'name' => 'Always Available Dish', 'cost' => 1, 'price' => 2, 'stock' => 0, 'stock_mode' => 'untracked']);

        $response = $this->actingAs($owner, 'web')->get('/app/stock?stock_status=out');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('products.data', 0));
    }
}
