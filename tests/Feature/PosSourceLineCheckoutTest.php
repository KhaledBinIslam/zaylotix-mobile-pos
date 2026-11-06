<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 (source_lines) — POS checkout resolving which supplier/brand
 * lot a sale actually depletes: FIFO when it's unambiguous, an explicit
 * pick (scan or picker) honored exactly, cost snapshotted from the REAL
 * lot (not a blended average), and the lot's own stock decremented
 * alongside the variant/product mirror.
 */
class PosSourceLineCheckoutTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aLineShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['source_lines', 'product_variants', 'suppliers'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);

        return [$shop, $owner];
    }

    public function test_a_single_lot_sells_directly_no_explicit_pick_needed(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $manik = Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 4]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 4, 'cost' => 600, 'price' => 1290]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $manik->id, 'cost' => 600, 'price' => 1290, 'stock' => 4]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 3870]],
        ])->assertOk();

        $this->assertEquals(1, (float) $line->fresh()->stock); // 4-3
        $this->assertEquals(1, (float) $variant->fresh()->stock);
        $item = SaleItem::sole();
        $this->assertSame($line->id, $item->product_source_id);
        $this->assertEquals(600, (float) $item->cost);
    }

    public function test_lots_auto_resolve_fifo_oldest_first_when_that_one_lot_covers_the_line(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $manik = Supplier::create(['name' => 'Manik']);
        $bulu = Supplier::create(['name' => 'Bulu']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 6, 'cost' => 600, 'price' => 1290]);
        $older = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $manik->id, 'cost' => 600, 'price' => 1290, 'stock' => 4]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $bulu->id, 'cost' => 650, 'price' => 1290, 'stock' => 2]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 3870]],
        ])->assertOk();

        $item = SaleItem::sole();
        $this->assertSame($older->id, $item->product_source_id); // the OLDEST lot, not Bulu's
        $this->assertEquals(600, (float) $item->cost); // Manik's real cost, never a blend with Bulu's 650
        $this->assertEquals(1, (float) $older->fresh()->stock); // 4-3
    }

    /**
     * A line is never silently split across two lots or driven negative
     * — if the FIFO-chosen (or explicitly picked) lot can't cover the
     * whole qty, the sale is refused with a clear reason, same spirit
     * as the Part-1 plan's "add it as two cart lines instead" guidance.
     */
    public function test_a_line_that_would_span_two_lots_is_refused_never_goes_negative(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $manik = Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 6, 'cost' => 600, 'price' => 1290]);
        $small = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $manik->id, 'cost' => 600, 'price' => 1290, 'stock' => 2]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => null, 'cost' => 650, 'price' => 1290, 'stock' => 4]);

        $resp = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 3870]],
        ])->assertStatus(422);

        $this->assertStringContainsString('আলাদা লাইনে', $resp->json('message'));
        $this->assertEquals(2, (float) $small->fresh()->stock); // untouched — never went negative
        $this->assertSame(0, SaleItem::count());
    }

    public function test_an_explicit_pick_is_honored_exactly_never_silently_swapped(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $manik = Supplier::create(['name' => 'Manik']);
        $bulu = Supplier::create(['name' => 'Bulu']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 6]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 6, 'cost' => 600, 'price' => 1290]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $manik->id, 'cost' => 600, 'price' => 1290, 'stock' => 4]);
        $buluLine = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $bulu->id, 'cost' => 650, 'price' => 1400, 'stock' => 2]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_source_id' => $buluLine->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 2800]],
        ])->assertOk();

        $item = SaleItem::sole();
        $this->assertSame($buluLine->id, $item->product_source_id); // the EXPLICIT pick, even though Manik's lot was older/cheaper
        $this->assertEquals(650, (float) $item->cost);
        $this->assertEquals(1400, (float) $item->price);
        $this->assertEquals(0, (float) $buluLine->fresh()->stock);
    }

    public function test_an_out_of_stock_explicit_pick_is_refused_not_silently_substituted(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $manik = Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 4]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 4, 'cost' => 600, 'price' => 1290]);
        $empty = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $manik->id, 'cost' => 600, 'price' => 1290, 'stock' => 0]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_source_id' => $empty->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 1290]],
        ])->assertStatus(422);

        $this->assertSame(0, SaleItem::count());
    }

    public function test_scanning_a_lots_own_barcode_resolves_product_variant_and_source(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $manik = Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 4]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 4, 'cost' => 600, 'price' => 1290]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $manik->id, 'cost' => 600, 'price' => 1290, 'stock' => 4]);
        // the controller writes ZL{id} itself normally; set it directly for this lookup test
        $line->update(['barcode' => 'ZL'.$line->id]);

        $resp = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/'.$line->barcode)->assertOk()->json();
        $this->assertTrue($resp['found']);
        $this->assertSame($variant->id, $resp['variant_id']);
        $this->assertSame($line->id, $resp['source_id']);
        $this->assertSame($product->id, $resp['product']['id']);
    }

    public function test_a_plain_variantless_products_own_lot_resolves_the_same_way(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $torchSupplier = Supplier::create(['name' => 'Torch Co']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Torch', 'cost' => 130, 'price' => 250, 'stock' => 5]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => null, 'supplier_id' => $torchSupplier->id, 'cost' => 130, 'price' => 250, 'stock' => 5]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
        ])->assertOk();

        $this->assertEquals(3, (float) $line->fresh()->stock);
        $this->assertSame($line->id, SaleItem::sole()->product_source_id);
    }

    public function test_a_shop_without_the_feature_sells_exactly_as_before_even_with_rows_present(): void
    {
        // source_lines NOT granted — confirms the feature gate, not just
        // the route gate, and that an orphaned product_sources row (e.g.
        // the feature was switched off after being on) is simply ignored
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 5, 'cost' => 100, 'price' => 200]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 999, 'price' => 999, 'stock' => 5]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertOk();

        $item = SaleItem::sole();
        $this->assertNull($item->product_source_id);
        $this->assertEquals(100, (float) $item->cost); // the MIRROR's cost, never the stray line's 999
        $this->assertEquals(200, (float) $item->price);
    }

    public function test_a_foreign_shops_source_id_is_refused_not_silently_used(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 5, 'cost' => 100, 'price' => 200]);
        // a REAL line on this variant, so the resolver is actually in
        // source-tracking mode for this item (not just falling through
        // because no lines exist here at all)
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'cost' => 100, 'price' => 200, 'stock' => 5]);

        [$shopB] = $this->createShopWithOwner();
        $foreignProduct = Product::withoutGlobalScopes()->create(['shop_id' => $shopB->id, 'name' => 'Other Shop Boot', 'cost' => 1, 'price' => 1, 'stock' => 5]);
        $foreignLine = ProductSource::withoutGlobalScopes()->create(['shop_id' => $shopB->id, 'product_id' => $foreignProduct->id, 'cost' => 1, 'price' => 1, 'stock' => 5]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_source_id' => $foreignLine->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertStatus(422);
    }
}
