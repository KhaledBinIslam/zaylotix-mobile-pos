<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Reports::productProfitReport() — Khaled's explicit request. topProducts()/
 * bottomProducts() already carry revenue/profit per product, but only to
 * rank by quantity sold. This is the same numbers ranked by profit instead,
 * with margin % added, so a high-volume/low-margin product doesn't hide a
 * low-volume/high-margin one.
 */
class ProductProfitReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_ranks_products_by_profit_not_quantity_sold(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // sells a lot but thin margin
        $highVolume = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 48, 'price' => 50, 'stock' => 100]);
        // sells rarely but fat margin
        $highMargin = Product::create(['shop_id' => $shop->id, 'name' => 'Perfume', 'cost' => 200, 'price' => 500, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $highVolume->id, 'qty' => 20]],
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertOk(); // profit = (50-48)*20 = 40

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $highMargin->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
        ])->assertOk(); // profit = (500-200)*1 = 300

        $today = now()->toDateString();
        $report = Reports::productProfitReport($today, $today);

        $this->assertCount(2, $report);
        // higher-profit product ranks first despite selling only 1 unit
        $this->assertSame('Perfume', $report[0]->product_name);
        $this->assertSame(300.0, (float) $report[0]->profit);
        $this->assertSame('Rice', $report[1]->product_name);
        $this->assertSame(40.0, (float) $report[1]->profit);
    }

    public function test_computes_margin_percentage(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 30, 'price' => 40, 'stock' => 50]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ])->assertOk(); // revenue 400, profit 100 -> margin 25%

        $today = now()->toDateString();
        $report = Reports::productProfitReport($today, $today);

        $this->assertCount(1, $report);
        $this->assertSame(25.0, $report[0]->margin_pct);
    }

    public function test_report_is_reachable_from_the_reports_page(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'reports');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 30, 'price' => 40, 'stock' => 50]);
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 40]],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->get('/app/reports');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('productProfitReport', 1)
            ->where('productProfitReport.0.product_name', 'Soap')
        );
    }
}
