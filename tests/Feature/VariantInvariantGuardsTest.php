<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A variant product's `products.stock` is a live-maintained sum of its
 * variants' stock (see ProductVariantTest) — Damage, Return, and Stock-count
 * all write to `products.stock` directly and none of them know about
 * variants, so each must refuse to touch a variant-having product rather
 * than silently desyncing the sum from its parts.
 */
class VariantInvariantGuardsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function variantProduct($shop): Product
    {
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);

        return $product;
    }

    public function test_damage_is_rejected_for_a_variant_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        $product = $this->variantProduct($shop);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'qty' => 2,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_return_is_rejected_for_a_variant_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = $this->variantProduct($shop);

        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 1, 'refund' => 20,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_stock_count_skips_a_variant_product_but_still_applies_the_rest(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'stock_count');
        $variantProduct = $this->variantProduct($shop);
        $plainProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-count', [
            'counts' => [
                ['product_id' => $variantProduct->id, 'counted' => 3], // must be skipped
                ['product_id' => $plainProduct->id, 'counted' => 45],
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals(10, $variantProduct->fresh()->stock); // untouched
        $this->assertEquals(45, $plainProduct->fresh()->stock); // applied
        $this->assertSame(1, StockCount::first()->changed); // only the plain product counted as a change
    }

    public function test_more_page_excludes_variant_products_from_the_shared_picker(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $variantProduct = $this->variantProduct($shop);
        $plainProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->get('/app/more');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->where('products.0.id', $plainProduct->id)
        );
    }
}
