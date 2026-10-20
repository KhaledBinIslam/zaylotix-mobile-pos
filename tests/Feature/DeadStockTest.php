<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "ডেড স্টক ক্লিয়ারেন্স" — see docs/FIX_PLAN.md's design note. No new
 * discount mechanism: markdown() just sets the existing
 * products.discount_price, which DiscountPriceCheckoutTest confirms now
 * actually applies at checkout (the real bug this phase also fixed).
 */
class DeadStockTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithFeature(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'dead_stock_clearance');

        return [$shop, $owner];
    }

    private function sellOn(int $shopId, int $productId, string $date): void
    {
        $sale = Sale::create(['shop_id' => $shopId, 'invoice_no' => 'INV-'.uniqid(), 'subtotal' => 0, 'discount' => 0, 'total' => 0, 'profit' => 0, 'date' => $date, 'time' => '12:00:00']);
        SaleItem::create(['shop_id' => $shopId, 'sale_id' => $sale->id, 'product_id' => $productId, 'product_name' => 'x', 'qty' => 1, 'price' => 10, 'discount' => 0, 'cost' => 5]);
    }

    public function test_a_product_never_sold_shows_up_as_dead_stock(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        Product::create(['shop_id' => $shop->id, 'name' => 'Forgotten Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->where('products.0.name', 'Forgotten Item')
        );
    }

    public function test_a_recently_sold_product_is_excluded(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Fresh Seller', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->sellOn($shop->id, $product->id, now()->subDays(5)->toDateString());

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page->has('products', 0));
    }

    public function test_a_product_last_sold_before_the_cutoff_still_counts_as_dead(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Old Seller', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->sellOn($shop->id, $product->id, now()->subDays(90)->toDateString());

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page->has('products', 1));
    }

    public function test_respects_a_custom_dead_stock_days_setting(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $shop->update(['dead_stock_days' => 10]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->sellOn($shop->id, $product->id, now()->subDays(20)->toDateString()); // dead under a 10-day window

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page
            ->where('deadStockDays', 10)
            ->has('products', 1)
        );
    }

    public function test_excludes_out_of_stock_products(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        Product::create(['shop_id' => $shop->id, 'name' => 'Already Empty', 'cost' => 50, 'price' => 100, 'stock' => 0]);

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page->has('products', 0));
    }

    public function test_excludes_a_product_with_variants(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 300, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 5]);

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page->has('products', 0));
    }

    public function test_excludes_a_weight_based_product(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        Product::create(['shop_id' => $shop->id, 'name' => 'Loose Rice', 'cost' => 50, 'price' => 80, 'stock' => 10, 'sold_by_weight' => true]);

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertInertia(fn ($page) => $page->has('products', 0));
    }

    public function test_update_days_saves_the_setting(): void
    {
        [, $owner] = $this->shopWithFeature();

        $this->actingAs($owner, 'web')->patch('/app/dead-stock/days', ['dead_stock_days' => 45])->assertRedirect();

        $this->assertDatabaseHas('shops', ['id' => $owner->shop_id, 'dead_stock_days' => 45]);
    }

    public function test_markdown_sets_discount_price_rounded_to_a_whole_taka(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 99, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post('/app/dead-stock/markdown', [
            'percent' => 20, 'product_ids' => [$product->id],
        ])->assertRedirect();

        $this->assertEquals(79.0, (float) $product->fresh()->discount_price); // round(99 * 0.8) = 79
    }

    public function test_markdown_never_produces_a_discount_price_equal_to_or_above_the_real_price(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Cheap Item', 'cost' => 1, 'price' => 2, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post('/app/dead-stock/markdown', [
            'percent' => 1, 'product_ids' => [$product->id],
        ])->assertRedirect();

        $this->assertLessThan(2.0, (float) $product->fresh()->discount_price);
    }

    public function test_clear_markdown_removes_the_discount_price(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'discount_price' => 80, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post('/app/dead-stock/clear-markdown', ['product_ids' => [$product->id]])->assertRedirect();

        $this->assertNull($product->fresh()->discount_price);
    }

    public function test_cannot_markdown_another_shops_product(): void
    {
        [$shopA, $ownerA] = $this->shopWithFeature();
        [$shopB] = $this->shopWithFeature();
        $otherProduct = Product::create(['shop_id' => $shopB->id, 'name' => 'Other Shop Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($ownerA, 'web')->post('/app/dead-stock/markdown', [
            'percent' => 20, 'product_ids' => [$otherProduct->id],
        ])->assertInvalid(['product_ids.0']);
    }

    public function test_route_requires_the_dead_stock_clearance_feature(): void
    {
        [, $owner] = $this->createShopWithOwner(); // no grantFeature

        $this->actingAs($owner, 'web')->get('/app/dead-stock')->assertForbidden();
    }
}
