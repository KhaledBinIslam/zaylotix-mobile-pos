<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 decision, explicitly pinned: Stock Count stays TOTAL-level (no
 * per-lot UI in phase 1), but its delta is always applied across the
 * item's existing lines, oldest lot first — so sum(lines.stock) ===
 * mirror.stock holds after every recount, exactly as it did before.
 */
class StockCountSourceLineFifoTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aLineShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['source_lines', 'product_variants', 'stock_count'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);

        return [$shop, $owner];
    }

    public function test_an_increase_lands_entirely_on_the_oldest_lot(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 6, 'cost' => 100, 'price' => 200]);
        $older = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 100, 'price' => 200, 'stock' => 2]);
        $newer = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 110, 'price' => 200, 'stock' => 4]);

        $this->actingAs($owner, 'web')->post(route('app.stockCount.store'), [
            'counts' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'counted' => 9]], // +3
        ])->assertSessionHasNoErrors();

        $this->assertEquals(9, (float) $variant->fresh()->stock);
        $this->assertEquals(5, (float) $older->fresh()->stock); // 2+3, the whole delta
        $this->assertEquals(4, (float) $newer->fresh()->stock); // untouched
        $this->assertEquals((float) $variant->fresh()->stock, $older->fresh()->stock + $newer->fresh()->stock); // the pinned invariant
    }

    public function test_a_decrease_drains_the_oldest_lot_first_then_spills_to_the_next_never_negative(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 6, 'cost' => 100, 'price' => 200]);
        $older = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 100, 'price' => 200, 'stock' => 2]);
        $newer = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 110, 'price' => 200, 'stock' => 4]);

        $this->actingAs($owner, 'web')->post(route('app.stockCount.store'), [
            'counts' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'counted' => 1]], // -5
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1, (float) $variant->fresh()->stock);
        $this->assertEquals(0, (float) $older->fresh()->stock); // drained first — never negative
        $this->assertEquals(1, (float) $newer->fresh()->stock); // absorbed the spillover (3 taken, 4-3)
        $this->assertEquals((float) $variant->fresh()->stock, $older->fresh()->stock + $newer->fresh()->stock); // the pinned invariant
    }

    public function test_a_plain_products_own_lines_are_covered_too(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Torch', 'cost' => 130, 'price' => 250, 'stock' => 5]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'cost' => 130, 'price' => 250, 'stock' => 5]);

        $this->actingAs($owner, 'web')->post(route('app.stockCount.store'), [
            'counts' => [['product_id' => $product->id, 'counted' => 7]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(7, (float) $product->fresh()->stock);
        $this->assertEquals(7, (float) $line->fresh()->stock);
    }

    public function test_a_shop_without_the_feature_is_unaffected_even_with_stray_lines(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // no source_lines
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'stock_count');
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 6, 'cost' => 100, 'price' => 200]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 100, 'price' => 200, 'stock' => 6]);

        $this->actingAs($owner, 'web')->post(route('app.stockCount.store'), [
            'counts' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'counted' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(2, (float) $variant->fresh()->stock);
        $this->assertEquals(6, (float) $line->fresh()->stock); // untouched — the feature is off
    }
}
