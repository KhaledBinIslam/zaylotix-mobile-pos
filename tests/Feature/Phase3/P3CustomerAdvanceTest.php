<?php

namespace Tests\Feature\Phase3;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * D12 (owner decision 2026-11-28) — a customer's money never disappears when a bill is voided.
 *
 * The due screen keeps working exactly as before (a collection lowers the customer's due at once). What changes is the
 * void: whatever the customer has paid that no longer has a bill to pay — a collection taken without picking an invoice
 * included — becomes their ADVANCE (store credit), which can later be applied to a new bill. The identity that must
 * always hold:   cash + customers' dues − customers' advance  =  what the ledgers say.
 */
class P3CustomerAdvanceTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    private int $shoe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['parties']);
        $this->shoe = $this->p3Product('Shoe', 60, 100, 100)->id;
    }

    private function creditBill(string $phone, int $qty, float $paid): Sale
    {
        return $this->p3Sell([['product_id' => $this->shoe, 'qty' => $qty]], $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [], ['customer_phone' => $phone, 'customer_name' => 'QA '.$phone]);
    }

    private function customer(string $phone): object
    {
        return DB::table('customers')->where('shop_id', $this->shop->id)->where('phone', $phone)->first();
    }

    private function cash(): float
    {
        return round((float) DB::table('shops')->where('id', $this->shop->id)->value('cash_balance'), 2);
    }

    public function test_an_unlinked_collection_survives_the_void_of_the_bill_as_advance(): void
    {
        $sale = $this->creditBill('01700000001', 2, 10);        // total 200, paid 10, due 190
        $c = $this->customer('01700000001');
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 100, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(90.0, (float) $this->customer('01700000001')->due);

        $this->p3Void($sale)->assertSessionHas('success');

        $c = $this->customer('01700000001');
        $this->assertEquals(0.0, (float) $c->due, 'nothing is owed any more');
        $this->assertEquals(100.0, (float) $c->advance_balance, 'the 100 they handed over is theirs');
        $this->assertEquals(10000.0 + 100.0, $this->cash(), 'the drawer keeps the 100; the 10 paid at the till went back with the void');
    }

    public function test_with_another_open_bill_the_advance_is_only_what_exceeds_what_is_still_owed(): void
    {
        $a = $this->creditBill('01700000002', 2, 0);            // A: 200 due
        $this->creditBill('01700000002', 1, 40);                // B: 100, paid 40, 60 due   -> due 260
        $c = $this->customer('01700000002');
        $this->assertEquals(260.0, (float) $c->due);
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 150, 'method' => 'cash'])->assertSessionHasNoErrors(); // due 110

        $this->p3Void($a)->assertSessionHas('success');

        $c = $this->customer('01700000002');
        // after voiding A the customer owes B's 60 and has paid 150 of which nothing is tied to a live bill but B's 60 -> 90 is credit
        $this->assertEquals(0.0, (float) $c->due, 'due clamps at zero');
        $this->assertEquals(90.0, (float) $c->advance_balance, '150 paid − 60 still owed on B');
        $this->assertEquals(
            (float) 10000 + 40 + 150 - 0,
            $this->cash(),
            'cash: B\'s 40 at the till + the 150 collected; A had no cash in it'
        );
    }

    public function test_no_collection_means_no_advance_a_plain_credit_bill_void_is_unchanged(): void
    {
        $sale = $this->creditBill('01700000003', 2, 0);
        $this->p3Void($sale)->assertSessionHas('success');

        $c = $this->customer('01700000003');
        $this->assertEquals([0.0, 0.0], [(float) $c->due, (float) $c->advance_balance]);
        $this->assertEquals(10000.0, $this->cash());
    }

    public function test_a_collection_linked_to_the_invoice_still_becomes_advance_as_before(): void
    {
        $sale = $this->creditBill('01700000004', 2, 0);          // 200 due
        $c = $this->customer('01700000004');
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 120, 'method' => 'cash', 'sale_id' => $sale->id])->assertSessionHasNoErrors();

        $this->p3Void($sale)->assertSessionHas('success');

        $c = $this->customer('01700000004');
        $this->assertEquals([0.0, 120.0], [(float) $c->due, (float) $c->advance_balance]);
    }

    public function test_voiding_the_same_bill_twice_credits_the_advance_once(): void
    {
        $sale = $this->creditBill('01700000005', 2, 0);
        $c = $this->customer('01700000005');
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 70, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->p3Void($sale)->assertSessionHas('success');
        $this->p3Void($sale);

        $this->assertEquals(70.0, (float) $this->customer('01700000005')->advance_balance, 'a second void is refused; the credit is not doubled');
        $this->assertEquals(10070.0, $this->cash());
    }

    public function test_the_advance_can_be_applied_to_a_later_bill_without_any_cash_moving(): void
    {
        $sale = $this->creditBill('01700000006', 2, 0);
        $c = $this->customer('01700000006');
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 150, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->p3Void($sale)->assertSessionHas('success');
        $this->creditBill('01700000006', 3, 0);                  // a new bill: 300 due
        $cashBefore = $this->cash();

        $this->p3As()->post("/app/customers/{$c->id}/payments/apply-advance")->assertSessionHasNoErrors();

        $c = $this->customer('01700000006');
        $this->assertEquals([150.0, 0.0], [(float) $c->due, (float) $c->advance_balance], '300 due − 150 advance');
        $this->assertEquals($cashBefore, $this->cash(), 'an internal transfer: the drawer does not move');
        $ledger = DB::table('payments')->where('customer_id', $c->id)->where('method', 'advance')->first();
        $this->assertNotNull($ledger, 'the collection ledger shows when the credit was applied');
        $this->assertEquals([300.0, 150.0], [(float) $ledger->due_before, (float) $ledger->due_after]);
    }

    public function test_the_advance_credit_leaves_a_trace_in_the_activity_log(): void
    {
        $sale = $this->creditBill('01700000007', 2, 0);
        $c = $this->customer('01700000007');
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 55, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->p3Void($sale)->assertSessionHas('success');

        $this->assertSame(1, DB::table('activity_logs')->where('action', 'customer.advance.credited')->count(), 'who/when/how much is recorded');
    }
}
