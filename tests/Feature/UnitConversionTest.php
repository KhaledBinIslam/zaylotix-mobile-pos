<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleItem;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The "sell 2 tablets out of a strip" / "sell single candies out of a box"
 * feature: a product's base stock stays in the smallest unit (pieces), and
 * selling a larger pack (box/strip) must decrement stock by qty × factor —
 * never just qty — regardless of which unit the cashier tapped.
 */
class UnitConversionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_selling_a_pack_unit_decrements_stock_by_the_full_factor(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'unit_conversion');

        $box = Unit::create(['shop_id' => $shop->id, 'name' => 'Box', 'name_en' => 'Box', 'code' => 'box']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Candy', 'cost' => 2, 'price' => 3, 'stock' => 300]);
        $productUnit = ProductUnit::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'unit_id' => $box->id, 'factor' => 100, 'price' => 250]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 250]],
        ]);

        $response->assertOk();
        $this->assertEquals(200, $product->fresh()->stock); // 300 - (1 box * 100) = 200
        $this->assertEquals(250.0, (float) $response->json('sale.total'));

        $item = SaleItem::first();
        $this->assertSame('Box', $item->unit_label);
        $this->assertSame(100, $item->unit_factor);
    }

    public function test_mixed_base_and_pack_units_in_one_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'unit_conversion');

        $strip = Unit::create(['shop_id' => $shop->id, 'name' => 'Strip', 'name_en' => 'Strip', 'code' => 'strip']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 100]);
        $productUnit = ProductUnit::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'unit_id' => $strip->id, 'factor' => 10, 'price' => 18]);

        // buy 1 strip (10 tablets) + 3 loose tablets in the same bill
        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'qty' => 1],
                ['product_id' => $product->id, 'product_unit_id' => null, 'qty' => 3],
            ],
            'payments' => [['method' => 'cash', 'amount' => 24]],
        ]);

        $response->assertOk();
        $this->assertEquals(87, $product->fresh()->stock); // 100 - 10 - 3
        $this->assertEquals(24.0, (float) $response->json('sale.total')); // 18 + 3*2
    }

    public function test_checkout_rejects_a_pack_unit_that_belongs_to_a_different_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'unit_conversion');

        $box = Unit::create(['shop_id' => $shop->id, 'name' => 'Box', 'name_en' => 'Box', 'code' => 'box']);
        $productA = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 50]);
        $productB = Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 50]);
        $productUnitForA = ProductUnit::create(['shop_id' => $shop->id, 'product_id' => $productA->id, 'unit_id' => $box->id, 'factor' => 10, 'price' => 15]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productB->id, 'product_unit_id' => $productUnitForA->id, 'qty' => 1]],
            'payments' => [],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(50, $productA->fresh()->stock);
        $this->assertEquals(50, $productB->fresh()->stock);
    }

    /**
     * Regression test: each cart line's stock check used to compare against
     * $product->stock directly, which is the same stale snapshot for every
     * line referencing the same product. Two lines for the same product
     * (e.g. "1 box" + some loose pieces) could each individually pass the
     * check against the full stock figure, while their combined base-unit
     * total oversold it — no concurrency needed, a single request did it.
     */
    public function test_checkout_rejects_two_lines_of_the_same_product_that_together_oversell_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'unit_conversion');

        $box = Unit::create(['shop_id' => $shop->id, 'name' => 'Box', 'name_en' => 'Box', 'code' => 'box']);
        // only 6 pieces in stock
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Candy', 'cost' => 1, 'price' => 2, 'stock' => 6]);
        $productUnit = ProductUnit::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'unit_id' => $box->id, 'factor' => 5, 'price' => 8]);

        // line A: 1 box (needs 5) passes against stock=6; line B: 3 loose
        // pieces (needs 3) also passes against the same stale stock=6 —
        // combined they need 8 against only 6 available.
        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'qty' => 1],
                ['product_id' => $product->id, 'product_unit_id' => null, 'qty' => 3],
            ],
            'payments' => [],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(6, $product->fresh()->stock);
    }
}
