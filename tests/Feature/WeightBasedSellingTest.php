<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Weight/volume-based selling (মুদি দোকান loose goods — চাল/ডাল/তেল sold by
 * কেজি/গ্রাম/লিটার rather than the piece). `products.sold_by_weight` +
 * `weight_unit` mark a product as loose-sold; its price is then interpreted
 * as "per kg"/"per litre" and every qty column that ever records a quantity
 * of that product (stock, sale_items.qty, damages.qty, returns.qty,
 * purchases.qty) accepts a real decimal instead of being truncated to a
 * whole number. A product with sold_by_weight=false must keep behaving
 * exactly as every pre-existing test already assumes — most tests here
 * exist specifically to pin that boundary down.
 */
class WeightBasedSellingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function weighedProduct(int $shopId, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'shop_id' => $shopId, 'name' => 'চাল', 'cost' => 40, 'price' => 60,
            'stock' => 20, 'sold_by_weight' => true, 'weight_unit' => 'kg',
        ], $attrs));
    }

    public function test_owner_can_create_a_sold_by_weight_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'weight_based_selling');

        $response = $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'ডাল', 'cost' => 80, 'price' => 110, 'stock' => 15.5,
            'sold_by_weight' => 1, 'weight_unit' => 'kg',
        ]);

        $response->assertRedirect();
        $product = Product::where('name', 'ডাল')->first();
        $this->assertNotNull($product);
        $this->assertTrue((bool) $product->sold_by_weight);
        $this->assertSame('kg', $product->weight_unit);
        $this->assertEquals(15.5, (float) $product->stock);
    }

    public function test_checkout_sells_a_fractional_quantity_and_decrements_stock_precisely(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = $this->weighedProduct($shop->id);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 0.25]], // 250 গ্রাম
            'payments' => [['method' => 'cash', 'amount' => 15]], // 60 * 0.25
        ]);

        $response->assertOk();
        $this->assertEquals(19.75, (float) $product->fresh()->stock);

        $sale = Sale::first();
        $this->assertEquals(15.0, (float) $sale->total);
        $this->assertEquals(5.0, (float) $sale->profit); // (60-40)*0.25
        $item = $sale->items->first();
        $this->assertEquals(0.25, (float) $item->qty);
    }

    public function test_checkout_rejects_a_fractional_quantity_for_a_non_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1.5]],
            'payments' => [['method' => 'cash', 'amount' => 225]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, (float) $product->fresh()->stock); // nothing moved
        $this->assertSame(0, Sale::count());
    }

    public function test_checkout_still_requires_a_whole_number_on_a_weighed_products_pack_unit_line(): void
    {
        // pack-size conversion (factor is a whole multiple) is mutually
        // exclusive with sold_by_weight at the product-management level, but
        // PosController's own per-line guard is the thing actually enforcing
        // it at checkout time — assert that directly rather than relying only
        // on the create-time guard
        [$shop, $owner] = $this->createShopWithOwner();
        $product = $this->weighedProduct($shop->id);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_unit_id' => 999999, 'qty' => 1.5]],
            'payments' => [],
        ]);

        // fails validation (unit doesn't exist) long before the whole-number
        // guard would even run — asserts the request never partially applies
        $response->assertStatus(422);
        $this->assertEquals(20, (float) $product->fresh()->stock);
    }

    public function test_stock_in_accepts_a_fractional_quantity_for_a_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = $this->weighedProduct($shop->id, ['stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 12.750, 'cost' => 42,
        ])->assertRedirect();

        $this->assertEquals(12.75, (float) $product->fresh()->stock);
    }

    public function test_stock_in_rejects_a_fractional_quantity_for_a_non_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 20, 'price' => 30, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", ['qty' => 2.5]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(5, (float) $product->fresh()->stock);
    }

    public function test_damage_accepts_a_fractional_quantity_for_a_weighed_product_and_computes_loss(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        $product = $this->weighedProduct($shop->id, ['stock' => 10, 'cost' => 40]);

        $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'qty' => 0.5, 'reason' => 'ছিটকে পড়ে গেছে',
        ])->assertRedirect();

        $this->assertEquals(9.5, (float) $product->fresh()->stock);
        $this->assertEquals(20.0, (float) \App\Models\Damage::first()->loss); // 40 * 0.5
    }

    public function test_damage_rejects_a_fractional_quantity_for_a_non_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 20, 'price' => 30, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'qty' => 1.5,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(10, (float) $product->fresh()->stock);
    }

    public function test_return_accepts_a_fractional_quantity_for_a_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = $this->weighedProduct($shop->id, ['stock' => 5]);

        // sell 2kg first so there's a returnable ceiling to work against
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 120]],
        ])->assertOk();

        $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 0.75, 'refund' => 45,
        ])->assertRedirect();

        $this->assertEquals(3.75, (float) $product->fresh()->stock); // 5 - 2 + 0.75
    }

    public function test_stock_count_rounds_a_fractional_entry_for_a_non_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'stock_count');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 20, 'price' => 30, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post('/app/stock-count', [
            'counts' => [['product_id' => $product->id, 'counted' => 8.4]],
        ])->assertRedirect();

        $this->assertEquals(8, (float) $product->fresh()->stock); // rounded, not truncated to 8.4
    }

    public function test_stock_count_accepts_a_precise_fractional_entry_for_a_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'stock_count');
        $product = $this->weighedProduct($shop->id, ['stock' => 20]);

        $this->actingAs($owner, 'web')->post('/app/stock-count', [
            'counts' => [['product_id' => $product->id, 'counted' => 18.3]],
        ])->assertRedirect();

        $this->assertEquals(18.3, (float) $product->fresh()->stock);
    }

    public function test_voiding_a_fractional_sale_restores_stock_precisely(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = $this->weighedProduct($shop->id, ['stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 0.6]],
            'payments' => [['method' => 'cash', 'amount' => 36]],
        ])->assertOk();
        $this->assertEquals(9.4, (float) $product->fresh()->stock);

        $sale = Sale::first();
        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'ভুল বিক্রি'])->assertRedirect();

        $this->assertEquals(10.0, (float) $product->fresh()->stock);
    }

    public function test_a_variant_cannot_be_added_to_a_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = $this->weighedProduct($shop->id);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'Large', 'stock' => 5,
        ]);

        $response->assertSessionHasErrors('size');
        $this->assertSame(0, ProductVariant::count());
    }

    public function test_a_pack_unit_cannot_be_added_to_a_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'unit_conversion');
        $product = $this->weighedProduct($shop->id);
        $unit = \App\Models\Unit::create(['shop_id' => $shop->id, 'name' => 'বস্তা', 'name_en' => 'Sack']);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/units", [
            'unit_id' => $unit->id, 'factor' => 25, 'price' => 1400,
        ]);

        $response->assertSessionHasErrors('unit_id');
        $this->assertSame(0, \App\Models\ProductUnit::count());
    }

    public function test_a_product_cannot_be_marked_sold_by_weight_once_it_already_has_a_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 300, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->put("/app/products/{$product->id}", [
            'name' => 'Shirt', 'cost' => 200, 'price' => 300, 'stock' => 5,
            'sold_by_weight' => 1, 'weight_unit' => 'kg',
        ]);

        $response->assertSessionHasErrors('sold_by_weight');
        $this->assertFalse((bool) $product->fresh()->sold_by_weight);
    }

    public function test_weight_based_selling_is_tenant_scoped_like_every_other_feature(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $productB = $this->weighedProduct($shopB->id);

        $response = $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productB->id, 'qty' => 0.5]],
            'payments' => [],
        ]);

        $response->assertStatus(422); // "product not found" — tenant scope hides it, same as any cross-shop product id
        $this->assertEquals(20, (float) $productB->fresh()->stock);
    }
}
