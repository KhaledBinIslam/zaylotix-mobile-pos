<?php

namespace Tests\Feature\Phase3;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — D. Supplier-wise inventory valuation on a lot-tracked shop (`source_lines`).
 *
 * Oracle: raw product_sources / product_variants rows, valued by hand. The invariant that makes the supplier
 * report trustworthy is "the lots of a size add up to that size's stock" — every movement is checked against it.
 */
class P3SupplierValuationTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    private Supplier $a;

    private Supplier $b;

    private Product $boot;

    private ProductVariant $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['source_lines']);
        $this->a = $this->p3Supplier('Supplier A');
        $this->b = $this->p3Supplier('Supplier B');
        $this->boot = $this->p3Product('Boot', 0, 150, 0);
        $this->m = $this->p3Variant($this->boot, 'M', 0, ['price' => 150]);
    }

    private function buy(Supplier $s, int $qty, float $cost, string $method = 'credit')
    {
        $r = $this->p3As()->post('/app/purchases', [
            'amount' => $qty * $cost, 'method' => $method, 'status' => 'received', 'supplier_id' => $s->id,
            'product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => $qty, 'cost' => $cost,
        ]);
        $this->assertFalse($r->getSession()->has('errors'), 'purchase refused: '.json_encode($r->getSession()->get('errors')?->all()));
    }

    private function sell(int ...$qtys): Sale
    {
        $items = array_map(fn ($q) => ['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => $q], $qtys);

        return $this->p3Sell($items, [['method' => 'cash', 'amount' => 150 * array_sum($qtys)]]);
    }

    /** @return array<int,float> supplier id => lot stock for the size */
    private function lots(): array
    {
        return DB::table('product_sources')->where('product_variant_id', $this->m->id)->whereNull('deleted_at')
            ->orderBy('id')->pluck('stock', 'supplier_id')->map(fn ($v) => (float) $v)->all();
    }

    private function sizeStock(): float
    {
        return (float) DB::table('product_variants')->where('id', $this->m->id)->value('stock');
    }

    private function assertLotsAddUpToTheSize(string $after): void
    {
        $this->assertEquals($this->sizeStock(), array_sum($this->lots()), "the supplier lots must add up to the size's stock after {$after}: ".json_encode(['lots' => $this->lots(), 'size' => $this->sizeStock()]));
    }

    /**
     * The independent oracle for the Accounts "stock value" card of a lot-tracked shop (D7): each lot at its OWN cost;
     * the pieces no lot covers at the size's current cost; lots larger than the shelf scaled down pro rata. Raw rows only.
     */
    private function cardValue(): float
    {
        $stock = $this->sizeStock();
        $mirror = (float) DB::table('product_variants')->where('id', $this->m->id)->value('cost');
        $lots = DB::table('product_sources')->where('product_variant_id', $this->m->id)->whereNull('deleted_at')->where('stock', '>', 0)->get();
        $lotQty = (float) $lots->sum('stock');
        if ($stock <= 0 || $lotQty <= 0) {
            return round($stock * $mirror, 2);
        }
        $scale = $lotQty > $stock ? $stock / $lotQty : 1.0;
        $value = (float) $lots->sum(fn ($l) => (float) $l->stock * (float) $l->cost) * $scale;

        return round($value + ($stock - min($lotQty, $stock)) * $mirror, 2);
    }

    /** @return array{props:array,rows:array<string,array>} */
    private function report(string $qs = ''): array
    {
        $props = [];
        $this->p3As()->get(route('app.accounts.stockValueBySupplier').$qs)->assertOk()->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });
        $rows = [];
        foreach ($props['rows'] as $r) {
            $rows[$r['unknown'] ? 'unknown' : $r['name']] = $r;
        }

        return ['props' => $props, 'rows' => $rows];
    }

    // ---- the brief's own example

    public function test_100_bought_at_100_then_40_sold_then_5_returned_to_the_supplier_values_55_at_100_for_that_supplier(): void
    {
        $this->buy($this->a, 100, 100);
        $this->sell(40);
        $r = $this->p3As()->postJson('/app/supplier-returns', ['supplier_id' => $this->a->id, 'settlement_method' => 'payable', 'reason' => 'QA', 'lines' => [['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => 5, 'amount' => 500]]]);
        $this->assertFalse($r->getSession()->has('errors'), json_encode($r->getSession()->get('errors')?->all()));

        $this->assertEquals(55.0, $this->sizeStock());
        $this->assertEquals([$this->a->id => 55.0], $this->lots(), 'the lot followed the sale (-40) and the return to the supplier (-5)');
        $this->assertEquals(9500.0, (float) DB::table('suppliers')->where('id', $this->a->id)->value('payable'), '10,000 bought on credit minus the 500 returned');

        $rep = $this->report();
        $this->assertEquals(5500.0, $this->cardValue(), '55 x 100 by hand');
        $this->assertEquals(5500.0, $rep['props']['total'], 'the report total is the card');
        $this->assertEquals(5500.0, $rep['rows']['Supplier A']['value']);
        $this->assertEquals(55.0, $rep['rows']['Supplier A']['qty']);
        $this->assertEquals(5500.0, array_sum(array_column($rep['props']['rows'], 'value')), 'rows add up to the card');
    }

    public function test_two_suppliers_at_different_prices_are_valued_each_at_its_own_price(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);

        $rep = $this->report();
        $this->assertEquals(500.0, $rep['rows']['Supplier A']['value']);
        $this->assertEquals(800.0, $rep['rows']['Supplier B']['value']);
        $this->assertLotsAddUpToTheSize('two purchases');

        // D7 (owner decision 2026-11-28): every lot at its OWN cost — 10x50 + 10x80 = 1,300 on the card, in the supplier
        // report and in the by-hand oracle; no artificial "supplier unknown" gap (it used to be 1,600 with 300 unknown)
        $this->assertEquals(1300.0, $this->cardValue());
        $this->assertEquals(1300.0, $rep['props']['total'], 'the card');
        $this->assertEquals(1300.0, array_sum(array_column($rep['props']['rows'], 'value')), 'rows add up to the card');
        $this->assertArrayNotHasKey('unknown', $rep['rows'], 'both suppliers are known — nothing is "unknown"');
    }

    public function test_cost_is_conserved_what_was_bought_equals_what_was_sold_at_lot_cost_plus_what_is_still_on_the_shelf(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);
        $this->sell(10, 3);     // 10 pieces of A's lot at 50, 3 of B's at 80

        $boughtAtCost = 10 * 50 + 10 * 80;
        $soldAtCost = (float) DB::table('sale_items')->where('product_variant_id', $this->m->id)->selectRaw('SUM(qty * cost) as t')->value('t');
        $this->assertEquals(10 * 50 + 3 * 80, $soldAtCost, 'profit uses each lot\'s own cost');
        $this->assertEquals(7 * 80.0, $this->cardValue(), '7 pieces of B\'s lot are left');
        $this->assertEquals(7 * 80.0, $this->report()['props']['total']);
        $this->assertEquals($boughtAtCost, $soldAtCost + $this->report()['props']['total'], 'valuation and profit follow ONE costing logic: nothing is lost or invented between "bought", "sold" and "on the shelf"');
    }

    public function test_a_shop_without_lots_is_still_valued_at_the_current_cost(): void
    {
        // a second shop on the default (no source_lines) rules: stock x the size's current cost, exactly as before
        [$plain, $owner] = $this->createShopWithOwner();
        $this->grantFeature($plain, 'accounts');
        $this->grantFeature($plain, 'product_variants');
        $p = Product::create(['shop_id' => $plain->id, 'name' => 'Plain Boot', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $plain->id, 'product_id' => $p->id, 'size' => 'M', 'color' => 'Black', 'stock' => 7, 'cost' => 90]);
        ProductVariant::create(['shop_id' => $plain->id, 'product_id' => $p->id, 'size' => 'L', 'color' => 'Black', 'stock' => 3, 'cost' => null]);
        Product::create(['shop_id' => $plain->id, 'name' => 'Rice', 'cost' => 60, 'price' => 80, 'stock' => 10]);

        \App\Support\Tenancy::clear();
        \App\Support\Tenancy::forgetShopCache();
        $props = null;
        $this->actingAs($owner, 'web')->get('/app/accounts')->assertOk()->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        $this->assertEquals(7 * 90 + 3 * 100 + 10 * 60, $props['stockValue'], '630 + 300 + 600');
    }

    public function test_a_cart_line_spanning_two_lots_is_refused_cleanly_and_two_lines_deplete_oldest_lot_first(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);
        $before = $this->p3Snap();

        $r = $this->p3Checkout([['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => 12]], [['method' => 'cash', 'amount' => 1800]]);
        $this->assertSame(422, $r->status(), 'one line may not blend two suppliers\' costs');
        $this->p3AssertSame($before, $this->p3Snap(), 'a refused bill moves nothing');

        $sale = $this->sell(10, 2);
        $this->assertEquals([$this->a->id => 0.0, $this->b->id => 8.0], $this->lots(), 'oldest lot first, then the next');
        $costs = DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->pluck('cost')->map(fn ($c) => (float) $c)->all();
        $this->assertEquals([50.0, 80.0], $costs, 'each line snapshots the cost of the lot it came from');
        $this->assertLotsAddUpToTheSize('a two-lot sale');
    }

    // ---- round trips that must keep the lots honest

    public function test_a_resalable_return_goes_back_into_the_exact_lot_it_came_from(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);
        $sale = $this->sell(10, 2);
        $itemOfB = (int) DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->skip(1)->value('id');

        $this->p3Return($sale, [['sale_item_id' => $itemOfB, 'qty' => 2, 'condition' => 'resalable']])->assertOk();

        $this->assertEquals([$this->a->id => 0.0, $this->b->id => 10.0], $this->lots());
        $this->assertLotsAddUpToTheSize('a return');
    }

    public function test_a_damaged_return_does_not_come_back_into_any_lot(): void
    {
        $this->buy($this->a, 10, 50);
        $sale = $this->sell(4);

        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'damaged']])->assertOk();

        $this->assertEquals([$this->a->id => 6.0], $this->lots());
        $this->assertLotsAddUpToTheSize('a damaged return');
    }

    public function test_a_stock_count_takes_from_the_oldest_lot_and_a_found_surplus_lands_on_the_oldest(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);

        $this->p3As()->post('/app/stock-count', ['counts' => [['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'counted' => 12]]])->assertSessionHasNoErrors();
        $this->assertEquals([$this->a->id => 2.0, $this->b->id => 10.0], $this->lots());
        $this->assertLotsAddUpToTheSize('counting down to 12');

        $this->p3As()->post('/app/stock-count', ['counts' => [['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'counted' => 15]]])->assertSessionHasNoErrors();
        $this->assertEquals([$this->a->id => 5.0, $this->b->id => 10.0], $this->lots());
        $this->assertLotsAddUpToTheSize('counting up to 15');
    }

    public function test_a_supplier_return_leaves_that_suppliers_own_lot_first(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);

        $this->p3As()->postJson('/app/supplier-returns', ['supplier_id' => $this->b->id, 'settlement_method' => 'payable', 'reason' => 'QA', 'lines' => [['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => 4, 'amount' => 320]]]);

        $this->assertEquals([$this->a->id => 10.0, $this->b->id => 6.0], $this->lots(), 'goods go back to the supplier they came from, not the oldest lot');
        $this->assertLotsAddUpToTheSize('a return to supplier B');
    }

    // ---- defects: executable bug reports (excluded from the default run)

    #[Group('phase3_defect')]
    public function test_DEFECT_voiding_a_bill_puts_the_pieces_back_on_the_shelf_but_not_into_the_supplier_lot(): void
    {
        $this->buy($this->a, 10, 50);
        $sale = $this->sell(4);
        $this->assertEquals([$this->a->id => 6.0], $this->lots());

        $this->p3Void($sale)->assertSessionHas('success');

        $this->assertEquals(10.0, $this->sizeStock(), 'the shelf is whole again');
        $this->assertEquals([$this->a->id => 10.0], $this->lots(), 'the supplier lot must be whole again too');
    }

    #[Group('phase3_defect')]
    public function test_DEFECT_a_damage_write_off_leaves_the_supplier_lot_untouched(): void
    {
        $this->buy($this->a, 10, 50);

        $this->p3As()->post('/app/damages', ['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => 3, 'reason' => 'QA'])->assertSessionHasNoErrors();

        $this->assertEquals(7.0, $this->sizeStock());
        $this->assertEquals([$this->a->id => 7.0], $this->lots(), 'the damaged pieces left a supplier lot too');
    }

    #[Group('phase3_defect')]
    public function test_DEFECT_sold_goods_are_credited_to_the_article_supplier_not_to_the_supplier_of_the_lot_they_came_from(): void
    {
        // the article carries no supplier of its own, as in a shop that buys the same article from several suppliers
        $this->buy($this->b, 10, 80);
        $this->sell(3);

        $rep = $this->report();

        $this->assertArrayHasKey('Supplier B', $rep['rows']);
        $this->assertEquals(3.0, $rep['rows']['Supplier B']['soldQty'], 'the 3 pieces sold came out of Supplier B\'s lot');
        $this->assertEquals(240.0, $rep['rows']['Supplier B']['soldCost'], '3 x 80');
        $this->assertArrayNotHasKey('unknown', $rep['rows'], 'nothing was bought or sold from an unknown supplier');
    }

    // ---- D7: edge cases of the one valuation

    public function test_lots_that_run_ahead_of_the_shelf_are_scaled_down_and_never_inflate_the_card(): void
    {
        $this->buy($this->a, 10, 50);
        // a damage write-off lowers the shelf (10 -> 7) — whether or not it also lowers the lot, the card never exceeds the shelf
        $this->p3As()->post('/app/damages', ['product_id' => $this->boot->id, 'product_variant_id' => $this->m->id, 'qty' => 3, 'reason' => 'QA'])->assertSessionHasNoErrors();

        $this->assertEquals(7.0, $this->sizeStock());
        $this->assertEquals(7 * 50.0, $this->cardValue(), '7 pieces at the lot cost');
        $rep = $this->report();
        $this->assertEquals(350.0, $rep['props']['total']);
        $this->assertEquals(350.0, array_sum(array_column($rep['props']['rows'], 'value')), 'the supplier report never exceeds the shelf');
        $this->assertEquals(7.0, array_sum(array_column($rep['props']['rows'], 'qty')), 'and neither does its quantity');
    }

    public function test_pieces_no_lot_covers_are_valued_at_the_size_cost_and_unknown_appears_only_when_no_supplier_is_on_record(): void
    {
        $this->buy($this->a, 10, 50);
        // 5 pieces appear on the shelf with no lot behind them (e.g. stock that predates lot tracking); the size's current cost is 60
        DB::table('product_variants')->where('id', $this->m->id)->update(['stock' => 15, 'cost' => 60]);
        DB::table('products')->where('id', $this->boot->id)->update(['stock' => 15]);

        $rep = $this->report();

        $this->assertEquals(10 * 50 + 5 * 60, $rep['props']['total'], 'lot at its own cost, the untracked 5 at the current cost');
        $this->assertEquals(500.0, $rep['rows']['Supplier A']['value']);
        $this->assertEquals(300.0, $rep['rows']['unknown']['value'], 'the article has no supplier on record, so this part really is unknown');
        $this->assertEquals(15 * 0 + 800.0, array_sum(array_column($rep['props']['rows'], 'value')));
    }

    public function test_the_stock_value_detail_page_total_equals_the_card_for_a_lot_tracked_shop(): void
    {
        $this->buy($this->a, 10, 50);
        $this->buy($this->b, 10, 80);
        $this->sell(4);

        $detail = null;
        $this->p3As()->get(route('app.accounts.stockValue'))->assertOk()->assertInertia(function ($page) use (&$detail) {
            $detail = $page->toArray()['props'];
        });

        $this->assertEquals(6 * 50 + 10 * 80, $detail['total'], 'A 6 left at 50 + B 10 at 80');
        $this->assertEquals($this->report()['props']['total'], $detail['total'], 'detail page, supplier report and card are one figure');
        $this->assertEquals(16.0, $detail['totalQty']);
    }
}
