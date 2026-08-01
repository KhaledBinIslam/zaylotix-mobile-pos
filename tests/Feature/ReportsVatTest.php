<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Reports::rangeStats() must report the VAT actually charged on each past
 * sale (stored on sale_items at checkout time), never re-derive it from the
 * shop's *current* vat_mode/turnover_rate — otherwise a shop that changes
 * its VAT settings would see every historical report silently recalculate.
 */
class ReportsVatTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_range_stats_reports_each_sales_own_stored_vat_not_the_shops_current_rate(): void
    {
        [$shop] = $this->createShopWithOwner(['vat_mode' => 'full']);
        Tenancy::set($shop->id);

        $today = now()->toDateString();
        Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => $today, 'time' => '10:00:00',
            'subtotal' => 1000, 'discount' => 0, 'vat' => 130.43, 'total' => 1000, 'profit' => 500, 'payment_mode' => 'cash',
        ]);

        // shop later switches VAT mode — past sales' stored vat must not move
        $shop->update(['vat_mode' => 'turnover', 'turnover_rate' => 3]);

        $stats = Reports::rangeStats($today, $today);

        $this->assertEquals(130.43, $stats['vat']); // the actually-charged amount, not round(1000*3/100,2) = 30.00
    }

    public function test_top_products_ranks_by_qty_sold_and_computes_profit(): void
    {
        [$shop] = $this->createShopWithOwner();
        Tenancy::set($shop->id);
        $today = now()->toDateString();

        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => $today, 'time' => '10:00:00',
            'subtotal' => 500, 'total' => 500, 'profit' => 150, 'payment_mode' => 'cash',
        ]);
        // Rice: sold 10 units at price 30, cost 20 -> profit (30-20)*10 = 100
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'Rice', 'qty' => 10, 'price' => 30, 'cost' => 20, 'discount' => 0]);
        // Oil: sold 2 units at price 100, cost 80 -> profit (100-80)*2 = 40
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'Oil', 'qty' => 2, 'price' => 100, 'cost' => 80, 'discount' => 0]);

        $top = Reports::topProducts($today, $today);

        $this->assertCount(2, $top);
        $this->assertSame('Rice', $top[0]->product_name); // higher qty sold ranks first
        $this->assertEquals(10, $top[0]->qty_sold);
        $this->assertEquals(100.0, (float) $top[0]->profit);
        $this->assertSame('Oil', $top[1]->product_name);
        $this->assertEquals(40.0, (float) $top[1]->profit);
    }

    public function test_top_products_excludes_voided_sales(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Tenancy::set($shop->id);
        $today = now()->toDateString();

        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => $today, 'time' => '10:00:00',
            'subtotal' => 300, 'total' => 300, 'profit' => 100, 'payment_mode' => 'cash',
        ]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'Rice', 'qty' => 5, 'price' => 60, 'cost' => 40, 'discount' => 0]);

        $sale->update(['voided_at' => now(), 'voided_reason' => 'test void', 'voided_by' => $owner->id]);

        $top = Reports::topProducts($today, $today);

        $this->assertCount(0, $top);
    }
}
