<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A variant (size/color) has its own independent stock, unlike product_units
 * (a pack-size conversion sharing one stock pool) — products.stock is kept
 * as the live sum of all its variants' stock, so every existing report/
 * alert/valuation query that reads products.stock keeps working unchanged.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creating_a_variant_adds_its_stock_to_the_parent_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'M', 'color' => 'Red', 'stock' => 10,
        ])->assertRedirect();

        $this->assertEquals(10, $product->fresh()->stock);
        $this->assertSame(1, ProductVariant::count());
    }

    public function test_two_variants_sum_correctly_into_the_parent_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", ['size' => 'M', 'stock' => 10]);
        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", ['size' => 'L', 'stock' => 5]);

        $this->assertEquals(15, $product->fresh()->stock);
    }

    public function test_checkout_decrements_the_specific_variant_and_the_parent_together(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $large = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 5]);
        $product->update(['stock' => 15]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $medium->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 1200]],
        ]);

        $response->assertOk();
        $this->assertEquals(7, $medium->fresh()->stock); // 10 - 3
        $this->assertEquals(5, $large->fresh()->stock); // untouched
        $this->assertEquals(12, $product->fresh()->stock); // 15 - 3
    }

    public function test_a_variant_priced_differently_than_the_parent_is_used_at_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $xl = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'XL', 'stock' => 5, 'price' => 450, 'cost' => 220]);
        $product->update(['stock' => 5]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $xl->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 450]],
        ]);

        $response->assertOk();
        $this->assertEquals(450.0, (float) $response->json('sale.total'));
    }

    public function test_a_product_with_variants_cannot_be_sold_without_specifying_one(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $product->update(['stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]], // no product_variant_id
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, $variant->fresh()->stock); // nothing moved
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_checkout_rejects_overselling_a_specific_variant_even_when_the_product_total_has_room(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 20]);
        $product->update(['stock' => 22]); // plenty in total, but only 2 in Medium

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $medium->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 2000]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(2, $medium->fresh()->stock);
    }

    public function test_voiding_a_variant_sale_restores_both_the_variant_and_the_parent_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $product->update(['stock' => 10]);

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $medium->id, 'qty' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 1600]],
        ]);
        $checkout->assertOk();
        $this->assertEquals(6, $medium->fresh()->stock);
        $this->assertEquals(6, $product->fresh()->stock);

        $saleId = $checkout->json('sale.id');
        $this->actingAs($owner, 'web')->delete("/app/sales/{$saleId}", ['reason' => 'Wrong size selected'])
            ->assertRedirect();

        $this->assertEquals(10, $medium->fresh()->stock);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_deleting_a_variant_with_leftover_stock_removes_it_from_the_parent_total(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $large = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 5]);
        $product->update(['stock' => 15]);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$medium->id}")->assertRedirect();

        $this->assertEquals(5, $product->fresh()->stock); // 15 - 10
        $this->assertSoftDeleted('product_variants', ['id' => $medium->id]);
        $this->assertEquals(5, $large->fresh()->stock); // untouched
    }

    public function test_a_duplicate_size_color_combination_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Red', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'M', 'color' => 'Red', 'stock' => 5,
        ]);

        $response->assertSessionHasErrors('size');
        $this->assertSame(1, ProductVariant::count());
        $this->assertEquals(0, $product->fresh()->stock); // unchanged — the duplicate attempt never applied
    }

    public function test_variants_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'product_variants');
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        $variantB = ProductVariant::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'size' => 'M', 'stock' => 5]);

        // shop A's owner tries to check out against shop B's variant id
        $response = $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productA->id, 'product_variant_id' => $variantB->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 2]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(5, $variantB->fresh()->stock); // untouched
    }
}
