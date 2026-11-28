<?php

namespace Tests\Feature\Phase3;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * D14 — a VOIDED bill must not count as a sale anywhere. The single-shop reports already hide it (Sale's global
 * "active" scope); every place that reached across shops with withoutGlobalScopes(), or read sale_items/sales through a
 * join, lost that protection. One live bill and one voided bill per shop, then every figure that is built from sales.
 *
 *   main shop   : live 200 (2 x 100, profit 80)   + voided 500 (5 x 100)
 *   branch shop : live 100 (1 x 100, profit 40)   + voided 300 (3 x 100)
 */
class P3VoidedBillsEverywhereTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    private \App\Models\Shop $branch;

    private \App\Models\User $branchOwner;

    private string $voidedMainInvoice;

    private string $liveMainInvoice;

    private int $mainProduct;

    private int $branchProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['dead_stock_clearance']);
        [$this->branch, $this->branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $this->shop->id, 'cash_balance' => 10000]);

        $supplier = $this->p3Supplier('Voided Supplier');
        $main = $this->p3Product('Shoe', 60, 100, 100, ['supplier_id' => $supplier->id]);
        $this->mainProduct = $main->id;
        $live = $this->p3Sell([['product_id' => $main->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]]);
        $gone = $this->p3Sell([['product_id' => $main->id, 'qty' => 5]], [['method' => 'cash', 'amount' => 500]]);
        $this->p3Void($gone)->assertSessionHas('success');
        $this->liveMainInvoice = $live->invoice_no;
        $this->voidedMainInvoice = $gone->invoice_no;

        // a product that only ever sold on a voided bill: it has NOT moved, so it is dead stock
        $ghost = $this->p3Product('Ghost', 10, 30, 8);
        $g = $this->p3Sell([['product_id' => $ghost->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 30]]);
        $this->p3Void($g)->assertSessionHas('success');

        // the branch (its own product rows, its own owner session)
        $bp = Product::create(['shop_id' => $this->branch->id, 'name' => 'Branch Shoe', 'cost' => 60, 'price' => 100, 'stock' => 50]);
        $this->branchProduct = $bp->id;
        $l = $this->branchCheckout($bp->id, 1);
        $v = $this->branchCheckout($bp->id, 3);
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $this->actingAs($this->branchOwner, 'web')->delete('/app/sales/'.$v, ['reason' => 'QA void at branch'])->assertSessionHas('success');
    }

    /** rings a bill at the branch; returns the sale id */
    private function branchCheckout(int $productId, int $qty): int
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $r = $this->actingAs($this->branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productId, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => 100 * $qty]],
        ]);
        $this->assertSame(200, $r->status(), 'branch checkout failed: '.substr((string) $r->getContent(), 0, 200));

        return (int) $r->json('sale.id');
    }

    private function ids(): array
    {
        return [$this->shop->id, $this->branch->id];
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    public function test_the_combined_report_counts_only_the_two_live_bills(): void
    {
        Tenancy::clear();
        Tenancy::set($this->shop->id);
        $c = Reports::combinedRangeStats($this->ids(), $this->today(), $this->today());

        $this->assertEquals(300.0, $c['grossSales'], 'live 200 + live 100, not 200+500+100+300');
        $this->assertEquals(120.0, $c['grossProfit'], '80 + 40');
        $this->assertSame(2, $c['count']);
        $rows = collect($c['byBranch'])->sortByDesc('total')->values();
        $this->assertEquals([[200.0, 1, 80.0], [100.0, 1, 40.0]], [
            [(float) $rows[0]['total'], $rows[0]['count'], (float) $rows[0]['profit']],
            [(float) $rows[1]['total'], $rows[1]['count'], (float) $rows[1]['profit']],
        ], 'per-branch rows: '.json_encode($c['byBranch']));
    }

    public function test_the_branch_breakdown_and_company_dashboard_count_only_live_bills(): void
    {
        $b = collect(Reports::companyBranchBreakdown($this->ids(), $this->today(), $this->today()));
        $main = $b->firstWhere('shop_id', $this->shop->id);
        $branch = $b->firstWhere('shop_id', $this->branch->id);
        $this->assertEquals([200.0, 1, 80.0], [$main['sales_total'], $main['sales_count'], $main['profit']], 'main shop row');
        $this->assertEquals([100.0, 1, 40.0], [$branch['sales_total'], $branch['sales_count'], $branch['profit']], 'branch row');
        // The dashboard's own trend chart and "recent invoices" use MySQL's DATE_FORMAT, which SQLite does not have, so
        // that page cannot be rendered here: it is verified on MariaDB by tests/QA/concurrency/run.php scenario R1.
    }

    public function test_the_stock_movement_log_does_not_list_a_voided_sale_as_stock_going_out(): void
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $props = null;
        $this->actingAs($this->owner, 'web')->get('/app/stock-movements')->assertOk()->assertInertia(function ($p) use (&$props) {
            $props = $p->toArray()['props'];
        });
        $sales = collect($props['movements'])->where('type', 'sale');
        $refs = $sales->pluck('ref')->all();

        $this->assertContains($this->liveMainInvoice, $refs);
        $this->assertNotContains($this->voidedMainInvoice, $refs, 'the voided bill gave its stock back; listing it as an outflow breaks in − out = stock');
        $this->assertEquals(3.0, (float) $sales->sum('qty_out'), 'stock really out by sales: 2 at the main shop + 1 at the branch (a voided bill\'s pieces came back, they are not an outflow)');
        $this->assertCount(2, $sales, 'two live sale lines, no nameless leftovers: '.json_encode($sales->values()->all()));
    }

    public function test_a_product_that_only_sold_on_a_voided_bill_is_dead_stock(): void
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $props = null;
        $this->actingAs($this->owner, 'web')->get('/app/dead-stock')->assertOk()->assertInertia(function ($p) use (&$props) {
            $props = $p->toArray()['props'];
        });
        $names = collect($props['products']['data'] ?? $props['products'])->pluck('name')->all();

        $this->assertContains('Ghost', $names, 'its only sale was voided, so it has not really moved');
        $this->assertNotContains('Shoe', $names, 'Shoe has a live recent sale');
    }

    public function test_the_supplier_page_counts_only_live_sold_units(): void
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $props = null;
        $this->actingAs($this->owner, 'web')->get('/app/suppliers')->assertOk()->assertInertia(function ($p) use (&$props) {
            $props = $p->toArray()['props'];
        });
        $row = collect($props['suppliers'])->firstWhere('name', 'Voided Supplier');

        $this->assertEquals(2.0, (float) $row['stats']['sold_units'], 'only the live bill: 2 units, not 2+5');
        $this->assertEquals(200.0, (float) $row['stats']['sold_revenue']);
    }

    public function test_the_home_best_seller_fallback_ignores_voided_bills(): void
    {
        // a shop whose only sales were voided: nothing has really sold, so nothing is a best seller
        [$shop, $owner] = $this->createShopWithOwner();
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'OnlyVoided', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $r = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', ['items' => [['product_id' => $p->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 4]]]);
        $this->assertSame(200, $r->status(), substr((string) $r->getContent(), 0, 200));
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $this->actingAs($owner, 'web')->delete('/app/sales/'.$r->json('sale.id'), ['reason' => 'QA void at home'])->assertSessionHas('success');

        Tenancy::clear();
        Tenancy::forgetShopCache();
        $props = null;
        $this->actingAs($owner, 'web')->get('/app/home')->assertOk()->assertInertia(function ($pg) use (&$props) {
            $props = $pg->toArray()['props'];
        });
        $names = collect($props['bestSellers'] ?? [])->pluck('product_name')->all();

        $this->assertNotContains('OnlyVoided', $names, 'a voided sale is not a best seller');
    }

    public function test_the_admin_platform_figures_count_only_live_bills(): void
    {
        $undelivered = $this->p3Sell([['product_id' => $this->mainProduct, 'qty' => 1]], [['method' => 'cash', 'amount' => 100]]);
        DB::table('sales')->where('id', $undelivered->id)->update(['counted_in_reports' => false]); // an online order not delivered yet

        $admin = Admin::create(['name' => 'Super', 'email' => 'd14-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
        $stats = null;
        $this->actingAs($admin, 'admin')->get('/admin/analytics')->assertOk()->assertInertia(function ($p) use (&$stats) {
            $stats = $p->toArray()['props']['stats'];
        });

        // live: main 200 + branch 100 (the 100 on the not-yet-delivered order is not a sale yet)
        $this->assertEquals(300.0, $stats['salesToday'], 'salesToday');
        $this->assertSame(2, $stats['billsToday'], 'billsToday');
        $this->assertEquals(300.0, $stats['salesMonth'], 'salesMonth');
    }
}
