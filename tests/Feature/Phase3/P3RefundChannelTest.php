<?php

namespace Tests\Feature\Phase3;

use App\Models\Sale;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * D2 (owner decision 2026-11-28) — a refund goes back the way the customer paid. A bKash payment is refunded from the
 * bKash/bank balance, never from the cash drawer; a split payment is refunded in the same proportions; the cash drawer
 * and the bank balance must never move wrongly. A void after a return restores exactly the legs the refund took.
 */
class P3RefundChannelTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    private int $shoe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['parties']);
        $this->shoe = $this->p3Product('Shoe', 60, 100, 100)->id;
    }

    private function bal(): array
    {
        return [
            'cash' => round((float) DB::table('shops')->where('id', $this->shop->id)->value('cash_balance'), 2),
            'bank' => round((float) DB::table('shops')->where('id', $this->shop->id)->value('bank_balance'), 2),
        ];
    }

    private function bill(array $payments, int $qty = 3, array $extra = []): Sale
    {
        return $this->p3Sell([['product_id' => $this->shoe, 'qty' => $qty]], $payments, $extra);
    }

    private function returnOne(Sale $sale, int $qty = 1)
    {
        return $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => $qty, 'condition' => 'resalable']]);
    }

    public function test_a_bkash_bill_is_refunded_from_the_bank_not_the_cash_drawer(): void
    {
        $sale = $this->bill([['method' => 'bkash', 'amount' => 300]]);
        $this->assertSame(['cash' => 10000.0, 'bank' => 300.0], $this->bal());

        $this->returnOne($sale)->assertOk();

        $this->assertSame(['cash' => 10000.0, 'bank' => 200.0], $this->bal(), '100 went back by bKash; the drawer is untouched');
    }

    public function test_a_cash_bill_is_still_refunded_from_cash(): void
    {
        $sale = $this->bill([['method' => 'cash', 'amount' => 300]]);

        $this->returnOne($sale)->assertOk();

        $this->assertSame(['cash' => 10200.0, 'bank' => 0.0], $this->bal());
    }

    public function test_a_split_payment_is_refunded_in_the_proportions_it_was_paid(): void
    {
        $sale = $this->bill([['method' => 'cash', 'amount' => 100], ['method' => 'bkash', 'amount' => 200]]); // 1/3 cash, 2/3 bKash
        $this->assertSame(['cash' => 10100.0, 'bank' => 200.0], $this->bal());

        $this->returnOne($sale)->assertOk(); // refund 100 -> 33.33 cash, 66.67 bank

        $b = $this->bal();
        $this->assertEqualsWithDelta(10100.0 - 33.33, $b['cash'], 0.005);
        $this->assertEqualsWithDelta(200.0 - 66.67, $b['bank'], 0.005);
        $this->assertEqualsWithDelta(100.0, (10100.0 - $b['cash']) + (200.0 - $b['bank']), 0.001, 'exactly the refund left the shop, in total');
    }

    public function test_a_part_paid_by_bkash_credit_bill_splits_due_share_and_bank_share(): void
    {
        $sale = $this->bill([['method' => 'bkash', 'amount' => 120]], 3, ['customer_phone' => '01711111111', 'customer_name' => 'QA']); // total 300, 120 paid, 180 due
        $this->assertSame(['cash' => 10000.0, 'bank' => 120.0], $this->bal());

        $this->returnOne($sale)->assertOk(); // refund 100: due share 60, money share 40 (all bKash)

        $this->assertSame(['cash' => 10000.0, 'bank' => 80.0], $this->bal(), '40 back by bKash');
        $this->assertEquals(120.0, (float) DB::table('customers')->where('phone', '01711111111')->value('due'), '180 − 60');
    }

    public function test_the_refund_legs_are_recorded_on_the_return_row(): void
    {
        $sale = $this->bill([['method' => 'cash', 'amount' => 100], ['method' => 'bkash', 'amount' => 200]]);

        $this->returnOne($sale)->assertOk();

        $row = DB::table('returns')->where('sale_id', $sale->id)->first();
        $this->assertEquals(100.0, (float) $row->refund);
        $this->assertEqualsWithDelta(100.0, (float) $row->refund_cash + (float) $row->refund_bank + (float) $row->refund_due, 0.001, 'cash + bank + due = the refund');
        $this->assertEquals(0.0, (float) $row->refund_due);
        $this->assertEquals(66.67, (float) $row->refund_bank);
    }

    public function test_void_after_a_return_restores_cash_and_bank_exactly(): void
    {
        $sale = $this->bill([['method' => 'cash', 'amount' => 100], ['method' => 'bkash', 'amount' => 200]]);
        $this->returnOne($sale)->assertOk();

        $this->p3Void($sale)->assertSessionHas('success');

        $this->assertSame(['cash' => 10000.0, 'bank' => 0.0], $this->bal(), 'a void nets to zero on BOTH balances');
        $this->assertEquals(100.0, (float) DB::table('products')->where('id', $this->shoe)->value('stock'));
    }

    public function test_void_after_a_bkash_return_restores_the_bank_balance(): void
    {
        $sale = $this->bill([['method' => 'bkash', 'amount' => 300]]);
        $this->returnOne($sale, 2)->assertOk();
        $this->assertSame(['cash' => 10000.0, 'bank' => 100.0], $this->bal());

        $this->p3Void($sale)->assertSessionHas('success');

        $this->assertSame(['cash' => 10000.0, 'bank' => 0.0], $this->bal());
    }

    public function test_the_cashier_cash_report_does_not_count_a_bkash_refund_as_cash_handed_back(): void
    {
        $sale = $this->bill([['method' => 'bkash', 'amount' => 300]]);
        $this->returnOne($sale)->assertOk();

        Tenancy::clear();
        Tenancy::set($this->shop->id);
        $d = now()->toDateString();
        $rows = Reports::cashierCashBreakdown($d, $d);

        $this->assertEquals(0.0, collect($rows)->sum('cash_returns'), 'no cash left the drawer');
        $this->assertEquals(0.0, collect($rows)->sum('expected_cash'), 'and no cash came in: the bill was paid by bKash');
    }

    public function test_an_exchange_for_a_cheaper_item_pays_the_difference_back_the_way_the_customer_paid(): void
    {
        $cheap = $this->p3Product('Sock', 5, 40, 50);
        $sale = $this->bill([['method' => 'bkash', 'amount' => 300]]);
        $this->assertSame(['cash' => 10000.0, 'bank' => 300.0], $this->bal());

        $r = $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']], 'exchange', ['new_items' => [['product_id' => $cheap->id, 'qty' => 1]]]);

        $this->assertSame(200, $r->status(), substr((string) $r->getContent(), 0, 200));
        $this->assertSame(['cash' => 10000.0, 'bank' => 240.0], $this->bal(), '100 credit − 40 new item = 60 back, by bKash');
    }

    /**
     * Probe (found while building D2): voiding an EXCHANGE bill reverses its tender lines — one of which is the
     * "trade_credit" that paid for it. That credit was never money in the bank, so the bank balance must not move.
     */
    public function test_voiding_an_exchange_bill_does_not_touch_the_bank_balance_for_its_trade_credit(): void
    {
        $other = $this->p3Product('Belt', 40, 100, 50);
        $sale = $this->bill([['method' => 'cash', 'amount' => 300]]);
        DB::table('shops')->where('id', $this->shop->id)->update(['bank_balance' => 500]);

        $r = $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']], 'exchange', ['new_items' => [['product_id' => $other->id, 'qty' => 1]]]);
        $this->assertSame(200, $r->status(), substr((string) $r->getContent(), 0, 200));
        $exchangeSaleId = (int) DB::table('returns')->where('sale_id', $sale->id)->value('exchange_sale_id');
        $bankBefore = $this->bal()['bank'];

        $this->p3Void(Sale::withoutGlobalScopes()->findOrFail($exchangeSaleId))->assertSessionHas('success');

        $this->assertEquals($bankBefore, $this->bal()['bank'], 'trade credit is not bank money: voiding the exchange bill must leave the bank balance alone');
    }

    /**
     * Rows written BEFORE this change carry no legs (and were refunded from cash). Voiding such a bill must behave exactly
     * as it always did — historical data is never reinterpreted.
     */
    public function test_a_legacy_return_row_without_legs_is_voided_exactly_as_before(): void
    {
        $sale = $this->bill([['method' => 'bkash', 'amount' => 300]]);
        $item = $this->p3ItemId($sale);
        // the old world: the refund of 100 left the CASH drawer, the return row has no legs, stock came back
        DB::table('shops')->where('id', $this->shop->id)->decrement('cash_balance', 100);
        DB::table('products')->where('id', $this->shoe)->increment('stock', 1);
        DB::table('returns')->insert([
            'shop_id' => $this->shop->id, 'sale_id' => $sale->id, 'sale_item_id' => $item, 'product_id' => $this->shoe, 'qty' => 1,
            'refund' => 100, 'cost' => 60, 'condition' => 'resalable', 'type' => 'return', 'date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(['cash' => 9900.0, 'bank' => 300.0], $this->bal());

        $this->p3Void($sale)->assertSessionHas('success');

        $this->assertSame(['cash' => 10000.0, 'bank' => 0.0], $this->bal(), 'the old refund came out of cash, so the void puts it back into cash');
    }
}
