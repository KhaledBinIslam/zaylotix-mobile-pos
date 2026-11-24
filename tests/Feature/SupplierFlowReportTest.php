<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockIntake;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The supplier report's flow figures: per supplier RECEIVED (stock_intakes),
 * SOLD (sale_items of the bills Reports counts) and sold-by-branch. Under a
 * shared pool both branches' sales and intakes are business-wide, the
 * supplier of a branch row is its MASTER article's, and sales with no
 * supplier land in "unknown" so the rows always add up to the sale_items.
 */
class SupplierFlowReportTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function pooledPair(): array
    {
        [$main, $mainOwner] = $this->createShopWithOwner(['name' => 'Baby Park']);
        [$branch, $branchOwner] = $this->createShopWithOwner(['name' => 'Liberty', 'parent_shop_id' => $main->id]);
        foreach ([$main, $branch] as $s) {
            foreach (['accounts', 'suppliers', 'product_variants'] as $f) {
                $this->grantFeature($s, $f);
            }
        }
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');

        return [$main, $mainOwner, $branch, $branchOwner];
    }

    private function sell(int $shopId, Product $product, float $qty, float $price, float $cost, string $date, array $saleOverrides = []): Sale
    {
        static $n = 0;
        $sale = Sale::create(array_merge([
            'shop_id' => $shopId, 'invoice_no' => 'T-'.(++$n).'-'.$shopId, 'date' => $date, 'time' => '12:00:00',
            'subtotal' => $qty * $price, 'discount' => 0, 'service_charge' => 0, 'vat' => 0, 'total' => $qty * $price, 'profit' => $qty * ($price - $cost),
            'payment_mode' => 'cash', 'counted_in_reports' => true,
        ], $saleOverrides));
        SaleItem::withoutGlobalScopes()->create([
            'shop_id' => $shopId, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'qty' => $qty, 'price' => $price, 'discount' => 0, 'cost' => $cost,
        ]);

        return $sale;
    }

    private function props($response): array
    {
        $props = [];
        $response->assertOk()->assertInertia(function ($page) use (&$props) {
            $page->component('App/Accounts/StockValueBySupplier');
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    private function rows(array $props): array
    {
        $out = [];
        foreach ($props['rows'] as $r) {
            $out[$r['unknown'] ? 'unknown' : $r['name']] = $r;
        }

        return $out;
    }

    public function test_pooled_sales_and_intakes_are_business_wide_split_by_supplier_and_branch(): void
    {
        [$main, $mainOwner, $branch, $branchOwner] = $this->pooledPair();
        Tenancy::set($main->id);
        $bulu = Supplier::create(['shop_id' => $main->id, 'name' => 'Bulu', 'payable' => 0]);

        $master = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 100, 'price' => 250, 'stock' => 20, 'supplier_id' => $bulu->id]);
        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $master->id)->firstOrFail();
        $this->assertNull($copy->supplier_id);

        // received: 20 pcs @ 100 at the main shop (supplier derived from the article)
        StockIntake::withoutGlobalScopes()->create(['shop_id' => $main->id, 'product_id' => $master->id, 'qty' => 20, 'unit_cost' => 100, 'total_cost' => 2000, 'source' => 'article_create']);

        // sold: 3 @ 250 at main, 2 @ 250 at the branch (its own COPY row — supplier comes from the master)
        $today = now()->toDateString();
        $this->sell($main->id, $master, 3, 250, 100, $today);
        $this->sell($branch->id, $copy, 2, 250, 100, $today);

        foreach ([$mainOwner, $branchOwner] as $owner) {
            $row = $this->rows($this->props($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier'))))['Bulu'];

            $this->assertEquals(2000, $row['received']);
            $this->assertEquals(20, $row['receivedQty']);
            $this->assertEquals(1250, $row['sold']);            // 5 x 250, both branches
            $this->assertEquals(500, $row['soldCost']);
            $byBranch = collect($row['branches'])->pluck('value', 'name')->all();
            $this->assertEquals(['Baby Park' => 750.0, 'Liberty' => 500.0], $byBranch);
        }
    }

    public function test_sales_without_a_supplier_reconcile_into_unknown_and_rows_add_up_to_the_sale_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['accounts', 'suppliers'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $bulu = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $withSupplier = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 50, 'price' => 100, 'stock' => 9, 'supplier_id' => $bulu->id]);
        $noSupplier = Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 30, 'price' => 80, 'stock' => 9]);

        $today = now()->toDateString();
        $this->sell($shop->id, $withSupplier, 4, 100, 50, $today);
        $this->sell($shop->id, $noSupplier, 3, 80, 30, $today);

        $props = $this->props($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier')));
        $rows = $this->rows($props);

        $this->assertEquals(400, $rows['Bulu']['sold']);
        $this->assertEquals(240, $rows['unknown']['sold']);

        $itemTotal = (float) DB::table('sale_items')->selectRaw('SUM(qty * price - discount) v')->value('v');
        $this->assertEquals($itemTotal, collect($props['rows'])->sum('sold'), 'supplier rows add up to the sale_items');
        $this->assertEquals($itemTotal, $props['totals']['sold']);
        // with no bill-level discount/vat the sale_items also equal Reports' gross sales
        $this->assertEquals((float) DB::table('sales')->sum('total'), $itemTotal);
    }

    public function test_a_voided_or_uncounted_sale_is_excluded(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['accounts', 'suppliers'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $bulu = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 50, 'price' => 100, 'stock' => 9, 'supplier_id' => $bulu->id]);

        $today = now()->toDateString();
        $this->sell($shop->id, $p, 1, 100, 50, $today);                                         // counts
        $this->sell($shop->id, $p, 5, 100, 50, $today, ['voided_at' => now()]);                  // voided
        $this->sell($shop->id, $p, 7, 100, 50, $today, ['counted_in_reports' => false]);         // online order not delivered yet

        $rows = $this->rows($this->props($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier'))));
        $this->assertEquals(100, $rows['Bulu']['sold']);
    }

    public function test_the_period_filter_including_a_custom_range_narrows_sold_and_received(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['accounts', 'suppliers'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $bulu = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 50, 'price' => 100, 'stock' => 9, 'supplier_id' => $bulu->id]);

        $this->sell($shop->id, $p, 1, 100, 50, '2026-01-10');
        $this->sell($shop->id, $p, 2, 100, 50, '2026-01-15');
        $this->sell($shop->id, $p, 4, 100, 50, '2026-01-20');
        $this->sell($shop->id, $p, 8, 100, 50, now()->toDateString());
        $old = StockIntake::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'product_id' => $p->id, 'qty' => 10, 'unit_cost' => 50, 'total_cost' => 500, 'source' => 'article_create']);
        DB::table('stock_intakes')->where('id', $old->id)->update(['created_at' => '2026-01-12 10:00:00']);
        StockIntake::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'product_id' => $p->id, 'qty' => 2, 'unit_cost' => 50, 'total_cost' => 100, 'source' => 'variant_stock_in']);

        $get = fn (string $qs) => $this->rows($this->props($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier').$qs)))['Bulu'];

        $this->assertEquals(1500, $get('')['sold']);                    // all time: 15 x 100
        $this->assertEquals(600, $get('')['received']);
        $this->assertEquals(800, $get('?period=today')['sold']);
        $this->assertEquals(100, $get('?period=today')['received']);

        $range = $get('?period=custom&from=2026-01-12&to=2026-01-20'); // both ends inclusive
        $this->assertEquals(600, $range['sold']);                       // 2 + 4
        $this->assertEquals(500, $range['received']);

        $this->assertEquals(100, $get('?period=custom&from=2026-01-10')['sold']);          // a single day
        $this->assertEquals(600, $get('?period=custom&from=2026-01-20&to=2026-01-12')['sold']); // swapped
        $this->assertEquals(1500, $get('?period=custom&from=garbage')['sold']);             // invalid -> all time
    }
}
