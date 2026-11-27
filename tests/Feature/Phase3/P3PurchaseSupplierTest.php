<?php

namespace Tests\Feature\Phase3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — B. Purchases, supplier returns, supplier payments and balances.
 * Same method as A: round trips and hand-computed figures; every figure read raw.
 */
class P3PurchaseSupplierTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot();
    }

    private function buy(array $over)
    {
        return $this->p3As()->post('/app/purchases', array_merge(['amount' => 0, 'method' => 'cash', 'status' => 'received'], $over));
    }

    private function supplierReturn(array $lines, string $settle, ?int $supplierId = null, bool $force = false)
    {
        return $this->p3As()->postJson('/app/supplier-returns', array_filter([
            'supplier_id' => $supplierId, 'settlement_method' => $settle, 'reason' => 'QA', 'lines' => $lines, 'force' => $force ?: null,
        ], fn ($v) => $v !== null));
    }

    public function test_cash_purchase_then_supplier_return_for_cash_restores_stock_and_money(): void
    {
        $p = $this->p3Product('Sneaker', 50, 120, 0);
        $m = $this->p3Variant($p, 'M', 4, ['cost' => 50]);
        $start = $this->p3Snap();

        $this->buy(['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 10, 'cost' => 80, 'amount' => 800])->assertSessionHasNoErrors();
        $afterBuy = $this->p3Snap();
        $this->assertEquals($start['cash'] - 800, $afterBuy['cash'], 'cash paid out');
        $this->assertEquals(14.0, $afterBuy['variant_stock'][$m->id], 'stock in');
        $this->assertEquals(14.0, $afterBuy['product_stock'][$p->id], 'product total follows its sizes');

        $this->supplierReturn([['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 10, 'amount' => 800]], 'cash')->assertSessionHasNoErrors();

        $end = $this->p3Snap();
        $this->assertEquals($start['cash'], $end['cash'], 'the supplier refunded in cash: the drawer is whole again');
        $this->assertEquals($start['variant_stock'], $end['variant_stock']);
        $this->assertEquals($start['product_stock'], $end['product_stock']);
    }

    public function test_credit_purchase_payment_and_return_against_payable_add_up_to_the_cent(): void
    {
        $s = $this->p3Supplier('Acme', 0);
        $p = $this->p3Product('Bag', 40, 90, 0);
        $start = $this->p3Snap();

        // buy 20 on credit @ 50 = 1000 payable
        $this->buy(['supplier_id' => $s->id, 'product_id' => $p->id, 'qty' => 20, 'cost' => 50, 'amount' => 1000, 'method' => 'credit'])->assertSessionHasNoErrors();
        $this->assertEquals(1000.0, (float) DB::table('suppliers')->where('id', $s->id)->value('payable'));
        $this->assertEquals($start['cash'], $this->p3Snap()['cash'], 'a credit purchase moves no cash');

        // return 3 pcs worth 150 against what we owe
        $this->supplierReturn([['product_id' => $p->id, 'qty' => 3, 'amount' => 150]], 'payable', $s->id)->assertSessionHasNoErrors();
        $this->assertEquals(850.0, (float) DB::table('suppliers')->where('id', $s->id)->value('payable'));
        $this->assertEquals(17.0, (float) DB::table('products')->where('id', $p->id)->value('stock'));

        // pay 850 cash: payable 0, drawer down 850
        $this->p3As()->post("/app/suppliers/{$s->id}/payments", ['amount' => 850, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(0.0, (float) DB::table('suppliers')->where('id', $s->id)->value('payable'));
        $this->assertEquals($start['cash'] - 850, $this->p3Snap()['cash']);
    }

    public function test_paying_a_supplier_more_than_is_owed_is_refused_and_changes_nothing(): void
    {
        $s = $this->p3Supplier('Acme', 300);
        $start = $this->p3Snap();

        $r = $this->p3As()->post("/app/suppliers/{$s->id}/payments", ['amount' => 300.01, 'method' => 'cash']);

        $r->assertSessionHasErrors('amount');
        $this->p3AssertSame($start, $this->p3Snap());
        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    public function test_a_pending_purchase_moves_nothing_until_received_and_receiving_twice_applies_it_once(): void
    {
        $p = $this->p3Product('Bag', 40, 90, 5);
        $start = $this->p3Snap();

        $this->buy(['product_id' => $p->id, 'qty' => 10, 'cost' => 40, 'amount' => 400, 'status' => 'pending'])->assertSessionHasNoErrors();
        $this->p3AssertSame($start, $this->p3Snap(), 'an order that has not arrived changes no balance');
        $purchaseId = DB::table('purchases')->value('id');

        $this->p3As()->post("/app/purchases/{$purchaseId}/receive")->assertSessionHasNoErrors();
        $once = $this->p3Snap();
        $this->assertEquals(15.0, $once['product_stock'][$p->id]);
        $this->assertEquals($start['cash'] - 400, $once['cash']);

        $this->p3As()->post("/app/purchases/{$purchaseId}/receive")->assertStatus(422); // a double tap
        $this->p3AssertSame($once, $this->p3Snap(), 'the second receive moved nothing');
    }

    public function test_weighted_average_costing_blends_a_new_purchase_into_the_cost(): void
    {
        $this->grantFeature($this->shop, 'weighted_average_costing');
        $p = $this->p3Product('Sneaker', 100, 200, 0);
        $m = $this->p3Variant($p, 'M', 10, ['cost' => 100]);

        $this->buy(['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 10, 'cost' => 200, 'amount' => 2000])->assertSessionHasNoErrors();

        $this->assertEquals(150.0, (float) DB::table('product_variants')->where('id', $m->id)->value('cost'), '10 @ 100 + 10 @ 200 = 20 @ 150');
    }

    public function test_without_weighted_average_the_latest_purchase_cost_replaces_the_old_one(): void
    {
        $p = $this->p3Product('Sneaker', 100, 200, 0);
        $m = $this->p3Variant($p, 'M', 10, ['cost' => 100]);

        $this->buy(['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 10, 'cost' => 200, 'amount' => 2000])->assertSessionHasNoErrors();

        $this->assertEquals(200.0, (float) DB::table('product_variants')->where('id', $m->id)->value('cost'), 'documented behaviour: latest cost wins (this is why supplier valuation is not FIFO for non-lot shops)');
    }

    public function test_a_past_sale_keeps_the_cost_it_was_sold_at_after_the_cost_changes(): void
    {
        $p = $this->p3Product('Bag', 40, 90, 20);
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 180]]);
        $profitBefore = (float) DB::table('sales')->where('id', $sale->id)->value('profit');

        $this->buy(['product_id' => $p->id, 'qty' => 10, 'cost' => 70, 'amount' => 700])->assertSessionHasNoErrors();

        $this->assertEquals(100.0, $profitBefore, '(90-40) x 2');
        $this->assertEquals(40.0, (float) DB::table('sale_items')->where('sale_id', $sale->id)->value('cost'), 'the line snapshot is untouched');
        $this->assertEquals($profitBefore, (float) DB::table('sales')->where('id', $sale->id)->value('profit'), 'history does not move when the cost does');
    }

    public function test_refused_purchases_change_nothing(): void
    {
        $p = $this->p3Product('Sneaker', 50, 120, 0);
        $m = $this->p3Variant($p, 'M', 4);
        $start = $this->p3Snap();

        $bad = [
            'zero amount' => ['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 5, 'cost' => 10, 'amount' => 0],
            'negative amount' => ['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 5, 'cost' => 10, 'amount' => -50],
            'variant product without naming the size' => ['product_id' => $p->id, 'qty' => 5, 'cost' => 10, 'amount' => 50],
            'fractional pieces of a size' => ['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 2.5, 'cost' => 10, 'amount' => 25],
            'size of another product' => ['product_id' => $p->id, 'product_variant_id' => 999999, 'qty' => 5, 'cost' => 10, 'amount' => 50],
            'negative cost' => ['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 5, 'cost' => -1, 'amount' => 50],
            'unknown method' => ['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 5, 'cost' => 10, 'amount' => 50, 'method' => 'barter'],
        ];
        foreach ($bad as $name => $payload) {
            $r = $this->buy($payload);
            $refused = $r->status() >= 400 || $r->getSession()->has('errors');
            $this->assertTrue($refused, "'{$name}' should be refused");
            $this->assertLessThan(500, $r->status(), "'{$name}' must be a clean refusal, not a crash");
            $this->p3AssertSame($start, $this->p3Snap(), "'{$name}' changed a balance");
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_a_supplier_return_beyond_the_shelf_needs_the_explicit_confirmation(): void
    {
        $p = $this->p3Product('Bag', 40, 90, 3);
        $start = $this->p3Snap();

        $this->supplierReturn([['product_id' => $p->id, 'qty' => 5, 'amount' => 200]], 'cash')->assertStatus(422);
        $this->p3AssertSame($start, $this->p3Snap(), 'refused: no stock, no money');

        $this->supplierReturn([['product_id' => $p->id, 'qty' => 5, 'amount' => 200]], 'cash', null, true)->assertSessionHasNoErrors();
        $this->assertEquals(-2.0, (float) DB::table('products')->where('id', $p->id)->value('stock'), 'forced: stock goes below zero and says so');
    }

    public function test_a_supplier_with_a_balance_or_history_cannot_be_deleted(): void
    {
        $owing = $this->p3Supplier('Owing', 100);
        $clean = $this->p3Supplier('Clean', 0);

        $this->p3As()->delete("/app/suppliers/{$owing->id}")->assertSessionHasErrors('supplier');
        $this->assertDatabaseHas('suppliers', ['id' => $owing->id, 'deleted_at' => null]);

        $this->p3As()->delete("/app/suppliers/{$clean->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('suppliers', ['id' => $clean->id]);
    }

    /** control: the Stock page's stock-in DOES write the intake ledger, so the defect test below is a fair test of the Purchases screen */
    public function test_control_the_stock_page_stock_in_writes_the_intake_ledger(): void
    {
        $p = $this->p3Product('Bag', 40, 90, 0);

        $this->p3As()->post("/app/products/{$p->id}/stock-in", ['qty' => 5, 'cost' => 40])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('stock_intakes')->where('product_id', $p->id)->count());
        $this->assertEquals(5.0, (float) DB::table('stock_intakes')->where('product_id', $p->id)->value('qty'));
    }

    #[Group('phase3_defect')]
    public function test_DEFECT_goods_received_through_the_purchases_screen_are_missing_from_the_intake_ledger(): void
    {
        $p = $this->p3Product('Sneaker', 50, 120, 0);
        $m = $this->p3Variant($p, 'M', 4, ['cost' => 50]);
        $plain = $this->p3Product('Bag', 40, 90, 0);

        $this->buy(['product_id' => $p->id, 'product_variant_id' => $m->id, 'qty' => 10, 'cost' => 80, 'amount' => 800])->assertSessionHasNoErrors();
        $this->buy(['product_id' => $plain->id, 'qty' => 6, 'cost' => 40, 'amount' => 240])->assertSessionHasNoErrors();

        $this->assertEquals(10.0, (float) DB::table('stock_intakes')->where('product_variant_id', $m->id)->sum('qty'), 'the received sizes belong in the ledger behind "how much came in"');
        $this->assertEquals(6.0, (float) DB::table('stock_intakes')->where('product_id', $plain->id)->sum('qty'), 'and so do plain products');
    }

    public function test_merging_two_suppliers_keeps_the_total_owed_and_the_history(): void
    {
        $a = $this->p3Supplier('Acme', 400);
        $b = $this->p3Supplier('Acme Ltd', 250);
        $p = $this->p3Product('Bag', 40, 90, 0);
        $this->buy(['supplier_id' => $b->id, 'product_id' => $p->id, 'qty' => 5, 'cost' => 40, 'amount' => 200, 'method' => 'credit'])->assertSessionHasNoErrors();
        $owedBefore = (float) DB::table('suppliers')->sum('payable');

        $this->p3As()->post("/app/suppliers/{$b->id}/merge", ['into' => $a->id])->assertSessionHasNoErrors();

        $this->assertEquals($owedBefore, (float) DB::table('suppliers')->whereNull('deleted_at')->sum('payable'), 'nothing owed disappeared in the merge');
        $this->assertEquals(0, DB::table('purchases')->where('supplier_id', $b->id)->count(), 'the purchase history moved to the survivor');
        $this->assertEquals(1, DB::table('purchases')->where('supplier_id', $a->id)->count());
    }
}
