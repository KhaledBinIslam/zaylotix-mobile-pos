<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The Accounts page valued the shelves at SUM(products.cost *
 * products.stock) — the article's one headline cost. A size can be
 * bought at its own price now, and ProductVariant::effectiveCost(),
 * which POS checkout, returns, damages and every profit figure already
 * go through, prefers that. This page did not: measured on Baby Park's
 * branch, it read ৳526,675 against a true ৳544,825.
 */
class AccountsStockValueTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_each_size_is_valued_at_its_own_buying_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'accounts');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 7]);
        // the small sizes were bought cheaper than the article's headline
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'stock' => 4, 'cost' => 50]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 3, 'cost' => 150]);

        // 4x50 + 3x150 = 650, not the 7x100 = 700 this page used to report
        $this->actingAs($owner, 'web')->get('/app/accounts')
            ->assertInertia(fn ($page) => $page->where('stockValue', fn ($v) => (float) $v === 650.0));
    }

    public function test_a_size_with_no_price_of_its_own_still_falls_back_to_the_articles(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'accounts');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 5, 'cost' => null]);

        $this->actingAs($owner, 'web')->get('/app/accounts')
            ->assertInertia(fn ($page) => $page->where('stockValue', fn ($v) => (float) $v === 500.0));
    }

    public function test_a_product_with_no_variants_is_valued_exactly_as_before(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'accounts');
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 60, 'price' => 80, 'stock' => 10]);

        $this->actingAs($owner, 'web')->get('/app/accounts')
            ->assertInertia(fn ($page) => $page->where('stockValue', fn ($v) => (float) $v === 600.0));
    }

    public function test_a_products_own_stock_is_never_counted_on_top_of_its_sizes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'accounts');

        // products.stock is kept as the sum of the variants' — counting
        // both would value every pair twice
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 5, 'cost' => 100]);

        $this->actingAs($owner, 'web')->get('/app/accounts')
            ->assertInertia(fn ($page) => $page->where('stockValue', fn ($v) => (float) $v === 500.0));
    }
}
