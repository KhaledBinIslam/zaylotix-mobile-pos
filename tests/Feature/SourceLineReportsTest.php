<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 (source_lines) — the EXACT lot a sale depleted knows its own
 * brand most precisely of all, so Reports::brandReport() prefers it over
 * the variant's/product's own brand_id when a sale was lot-resolved.
 */
class SourceLineReportsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_brand_report_prefers_the_sold_lots_own_brand(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        $lotus = Brand::create(['name' => 'Lotus']);
        // the HELMET itself carries no single brand — only the LOT does
        $helmet = Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'cost' => 100, 'price' => 200, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $helmet->id, 'color' => 'White', 'stock' => 0]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $helmet->id, 'product_variant_id' => $variant->id, 'brand_id' => $lotus->id, 'cost' => 100, 'price' => 200, 'stock' => 0]);

        $sale = Sale::create(['shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => '10:00', 'subtotal' => 0, 'discount' => 0, 'vat' => 0, 'total' => 0, 'profit' => 0, 'payment_mode' => 'cash', 'counted_in_reports' => true]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $helmet->id, 'product_variant_id' => $variant->id, 'product_source_id' => $line->id, 'product_name' => 'Helmet', 'qty' => 2, 'price' => 200, 'discount' => 0, 'cost' => 100]);

        $report = \App\Support\Reports::brandReport(now()->toDateString(), now()->toDateString());

        $byBrand = collect($report)->keyBy('brand');
        $this->assertEquals(400, $byBrand['Lotus']['revenue']); // attributed to the LOT's brand, not left in Nil
        $this->assertArrayNotHasKey('Nil', $byBrand->all());
    }
}
