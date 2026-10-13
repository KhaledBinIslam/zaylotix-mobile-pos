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
 * all write to `products.stock` directly, so none of them may touch a
 * variant-having product's stock in general (that's still rejected/skipped
 * below), but all three now accept a SPECIFIC variant instead of refusing
 * the product outright — moving that variant's own stock and the product
 * total together, same shape as StockTransferController.
 */
class VariantInvariantGuardsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function variantProduct($shop): array
    {
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 25]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10, 'cost' => 180]);
        $l = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 15]);

        return [$product, $m, $l];
    }

    public function test_damage_is_rejected_for_a_variant_product_with_no_variant_named(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        [$product] = $this->variantProduct($shop);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'qty' => 2,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(25, $product->fresh()->stock);
    }

    public function test_damage_writes_off_a_specific_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        [$product, $m, $l] = $this->variantProduct($shop);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'product_variant_id' => $m->id, 'qty' => 3,
        ]);

        $response->assertRedirect();
        $this->assertEquals(7, $m->fresh()->stock); // 10 - 3
        $this->assertEquals(15, $l->fresh()->stock); // untouched
        $this->assertEquals(22, $product->fresh()->stock); // 25 - 3, sum stays in sync
        $this->assertEquals(540, (float) \App\Models\Damage::first()->loss); // 180 (variant's own cost) * 3
    }

    public function test_damage_rejects_a_variant_that_belongs_to_a_different_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        [$product] = $this->variantProduct($shop);
        $other = Product::create(['shop_id' => $shop->id, 'name' => 'Other', 'cost' => 10, 'price' => 20, 'stock' => 0]);
        $foreignVariant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $other->id, 'size' => 'S', 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'product_variant_id' => $foreignVariant->id, 'qty' => 1,
        ]);

        $response->assertSessionHasErrors('product_variant_id');
        $this->assertEquals(5, $foreignVariant->fresh()->stock);
    }

    public function test_return_is_rejected_for_a_variant_product_with_no_variant_named(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        [$product] = $this->variantProduct($shop);

        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 1, 'refund' => 20,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(25, $product->fresh()->stock);
    }

    public function test_return_restocks_a_specific_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        [$product, $m] = $this->variantProduct($shop);
        // store()'s own "never sold more than this" cap needs a real sale on record
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $m->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 800]],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'product_variant_id' => $m->id, 'qty' => 1, 'refund' => 400,
        ]);

        $response->assertRedirect();
        $this->assertEquals(9, $m->fresh()->stock); // 10 - 2 (sold) + 1 (returned)
        $this->assertEquals(24, $product->fresh()->stock); // 25 - 2 + 1
    }

    public function test_stock_count_skips_a_variant_product_with_no_variant_named_but_still_applies_the_rest(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'stock_count');
        [$variantProduct] = $this->variantProduct($shop);
        $plainProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-count', [
            'counts' => [
                ['product_id' => $variantProduct->id, 'counted' => 3], // must be skipped — no variant named
                ['product_id' => $plainProduct->id, 'counted' => 45],
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals(25, $variantProduct->fresh()->stock); // untouched
        $this->assertEquals(45, $plainProduct->fresh()->stock); // applied
        $this->assertSame(1, StockCount::first()->changed); // only the plain product counted as a change
    }

    public function test_stock_count_reconciles_a_specific_variant_and_keeps_the_product_total_in_sync(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'stock_count');
        [$product, $m, $l] = $this->variantProduct($shop);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-count', [
            'counts' => [
                ['product_id' => $product->id, 'product_variant_id' => $m->id, 'counted' => 8], // was 10, short by 2
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals(8, $m->fresh()->stock);
        $this->assertEquals(15, $l->fresh()->stock); // untouched
        $this->assertEquals(23, $product->fresh()->stock); // 25 - 2, sum stays in sync
        $this->assertSame(1, StockCount::first()->changed);
    }

    public function test_more_page_now_includes_variant_products_with_their_variants(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        [$variantProduct, $m] = $this->variantProduct($shop);
        $plainProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $response = $this->actingAs($owner, 'web')->get('/app/more');

        // Product::orderBy('name') — "Rice" sorts before "Shirt"
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products', 2)
            ->where('products.1.id', $variantProduct->id)
            ->has('products.1.variants', 2));
    }
}
