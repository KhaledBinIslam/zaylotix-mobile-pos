<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The batch-tracking counterpart to Reports::itemWisePurchases() — qty_sold
 * is read straight off the same batch_allocations BatchStock::deduct()
 * already stamps onto each sale_item, so it can never drift from what the
 * checkout flow actually drew from each batch.
 */
class BatchWiseReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_report_shows_supplier_remaining_and_sold_qty_for_a_batch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Alpha Pharma', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 100]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'batch_no' => 'B1', 'expiry_date' => now()->addYear(), 'qty' => 100, 'cost' => 2]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 20]],
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ])->assertOk();

        $today = now()->toDateString();
        $report = collect(Reports::batchWiseReport($today, $today))->firstWhere('id', $batch->id);

        $this->assertNotNull($report);
        $this->assertSame('Napa', $report['product_name']);
        $this->assertSame('B1', $report['batch_no']);
        $this->assertSame('Alpha Pharma', $report['supplier_name']);
        $this->assertEquals(80, $report['qty_remaining']);
        $this->assertEquals(20, $report['qty_sold']);
    }

    public function test_a_fully_depleted_batch_still_shows_up_if_it_sold_within_the_range(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 10]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'B2', 'expiry_date' => now()->addYear(), 'qty' => 10, 'cost' => 2]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ])->assertOk();

        $this->assertEquals(0, $batch->fresh()->qty); // fully depleted

        $today = now()->toDateString();
        $report = collect(Reports::batchWiseReport($today, $today))->firstWhere('id', $batch->id);

        $this->assertNotNull($report); // still listed — it moved within this range
        $this->assertSame(10.0, $report['qty_sold']);
        $this->assertNull($report['supplier_name']); // never set
    }

    public function test_a_long_empty_batch_with_no_sales_in_range_does_not_clutter_the_report(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 0]);
        $emptyBatch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'OLD-EMPTY', 'qty' => 0, 'cost' => 2]);

        $today = now()->toDateString();
        $report = collect(Reports::batchWiseReport($today, $today))->firstWhere('id', $emptyBatch->id);

        $this->assertNull($report);
    }

    public function test_report_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Other', 'cost' => 1, 'price' => 2, 'stock' => 5]);
        ProductBatch::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'batch_no' => 'FOREIGN', 'qty' => 5, 'cost' => 1]);

        $this->actingAs($ownerA, 'web');

        $today = now()->toDateString();
        $report = Reports::batchWiseReport($today, $today);

        $this->assertCount(0, $report);
    }
}
