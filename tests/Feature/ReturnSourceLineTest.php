<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 (source_lines) — a resalable same-branch return restocks the
 * EXACT lot the sale actually depleted (via sale_items.product_source_id),
 * not just the variant/product mirror total.
 */
class ReturnSourceLineTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_resalable_return_restocks_the_originating_lot(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['source_lines', 'product_variants', 'suppliers', 'returns'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $bulu = Supplier::create(['name' => 'Bulu']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 650, 'price' => 1400, 'stock' => 2]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 2, 'cost' => 650, 'price' => 1400]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $bulu->id, 'cost' => 650, 'price' => 1400, 'stock' => 2]);

        $this->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 2800]],
        ])->assertOk();

        $this->assertEquals(0, (float) $line->fresh()->stock);
        $this->assertEquals(0, (float) $variant->fresh()->stock);

        $sale = Sale::sole();
        $saleItem = SaleItem::sole();
        $this->assertSame($line->id, $saleItem->product_source_id); // confirms the sale really did hit this lot

        $this->post(route('app.returns.process'), [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1, (float) $line->fresh()->stock); // back on the SAME lot it left from
        $this->assertEquals(1, (float) $variant->fresh()->stock);
    }

    public function test_a_lost_or_damaged_return_never_restocks_the_lot(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['source_lines', 'product_variants', 'suppliers', 'returns', 'damages'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 650, 'price' => 1400, 'stock' => 2]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 2, 'cost' => 650, 'price' => 1400]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 650, 'price' => 1400, 'stock' => 2]);

        $this->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 1400]],
        ])->assertOk();

        $sale = Sale::sole();
        $saleItem = SaleItem::sole();

        $this->post(route('app.returns.process'), [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 1, 'condition' => 'damaged']],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1, (float) $line->fresh()->stock); // 2-1 from the sale, stays there — a write-off, not a restock
    }
}
