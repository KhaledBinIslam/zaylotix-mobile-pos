<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Shop;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * No prior coverage existed for Reports::combinedRangeStats() at all —
 * added alongside converting it from PHP-side aggregation to SQL SUM()/
 * GROUP BY (see docs/SCALING_GUIDE.md item #6 and docs/FIX_PLAN.md's
 * Phase 38 follow-up note), since there was no pre-existing safety net to
 * lean on for this one specifically.
 */
class CombinedRangeStatsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function makeSale(Shop $shop, float $total, float $profit, string $date, string $saleType = 'retail'): Sale
    {
        return Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-'.uniqid(), 'date' => $date, 'time' => '10:00:00',
            'subtotal' => $total, 'discount' => 0, 'vat' => 0, 'total' => $total, 'profit' => $profit,
            'payment_mode' => 'cash', 'sale_type' => $saleType,
        ]);
    }

    public function test_totals_and_per_branch_breakdown_sum_correctly_across_three_shops(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branchA] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        [$branchB] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        $today = now()->toDateString();

        $this->makeSale($main, 200, 80, $today);
        $this->makeSale($branchA, 100, 40, $today);
        $this->makeSale($branchB, 50, 20, $today);
        $this->makeSale($branchB, 50, 20, $today);
        // an exchange sale counts toward revenue/profit but not the bill count
        $this->makeSale($branchA, 30, 10, $today, 'exchange');

        $stats = Reports::combinedRangeStats([$main->id, $branchA->id, $branchB->id], $today, $today);

        $this->assertEquals(430.0, $stats['grossSales']); // 200+100+50+50+30
        $this->assertEquals(4, $stats['count']); // exchange sale excluded from the bill count

        $this->assertCount(3, $stats['byBranch']);
        $totalsByCount = collect($stats['byBranch'])->sum('count');
        $this->assertEquals(5, $totalsByCount); // every sale, including the exchange, appears in the per-branch breakdown

        $byShopName = collect($stats['byBranch'])->keyBy('shop_name');
        $this->assertEquals(1, $byShopName[$main->name]['count']);
        $this->assertEquals(200.0, $byShopName[$main->name]['total']);
        $this->assertEquals(2, $byShopName[$branchA->name]['count']); // its retail sale + its exchange sale
        $this->assertEquals(130.0, $byShopName[$branchA->name]['total']); // 100 + 30
        $this->assertEquals(2, $byShopName[$branchB->name]['count']);
        $this->assertEquals(100.0, $byShopName[$branchB->name]['total']); // 50 + 50

        $totalsSum = round(collect($stats['byBranch'])->sum('total'), 2);
        $this->assertEquals(430.0, $totalsSum);
        $profitSum = round(collect($stats['byBranch'])->sum('profit'), 2);
        $this->assertEquals(170.0, $profitSum); // 80+40+20+20+10
    }

    public function test_a_shop_outside_the_given_id_list_never_leaks_in(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        [$unrelated] = $this->createShopWithOwner();
        $today = now()->toDateString();

        $this->makeSale($main, 100, 40, $today);
        $this->makeSale($branch, 50, 20, $today);
        $this->makeSale($unrelated, 9999, 9999, $today);

        $stats = Reports::combinedRangeStats([$main->id, $branch->id], $today, $today);

        $this->assertEquals(150.0, $stats['grossSales']);
        $this->assertCount(2, $stats['byBranch']);
    }
}
