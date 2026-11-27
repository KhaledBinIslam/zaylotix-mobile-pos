<?php

namespace Tests\Feature\Phase3;

use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — H. Financial reconciliation. A seeded random day through every cash-moving screen; after EVERY step three
 * identities are rebuilt from the raw ledger tables alone and compared with the balances the application keeps:
 *
 *   cash drawer   = opening + sale cash + customer collections - cash refunds - expenses - cash purchases
 *                   - supplier payments + cash supplier returns            (a voided bill and its returns net to zero)
 *   customer dues = what the live bills left unpaid - what returns took off the due - customer collections
 *   payables      = credit purchases - supplier payments - returns settled against payable
 *
 * If any screen forgot a ledger row (or wrote one it should not), an identity breaks at exactly that step.
 */
class P3ReconciliationTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    private const OPENING_CASH = 10000.0;

    private array $products = [];

    private array $suppliers = [];

    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['expenses']);
        $this->products = [$this->p3Product('Shoe', 60, 100, 200)->id, $this->p3Product('Bag', 30, 50, 200)->id];
        $this->suppliers = [$this->p3Supplier('S One')->id, $this->p3Supplier('S Two')->id];
        $this->customers = [
            ['id' => $this->p3Customer('01711111111')->id, 'phone' => '01711111111'],
            ['id' => $this->p3Customer('01722222222')->id, 'phone' => '01722222222'],
        ];
    }

    // ---------------------------------------------------------------- the oracles (raw tables only)

    private function liveSaleIds(): array
    {
        return DB::table('sales')->where('shop_id', $this->shop->id)->whereNull('voided_at')->pluck('id')->all();
    }

    /**
     * "Cash in the drawer + money customers still owe" — the one figure that does NOT depend on how a refund is split
     * between cash out and due reduced (cash out + due reduced = refund, always). The ledger cannot give that split
     * (returns.applied_to_due is a yes/no flag — finding D11), so the identity is stated on the sum:
     *   opening + live bills' totals - their refunds - expenses - cash purchases - supplier payments + cash supplier returns
     * (customer collections move money from "owed" to "cash": no effect on the sum).
     */
    private function expectedCashPlusDues(): float
    {
        $id = $this->shop->id;
        $live = $this->liveSaleIds();
        $billed = (float) DB::table('sales')->whereIn('id', $live)->sum('total');
        $bank = (float) DB::table('sale_payments')->whereIn('sale_id', $live)->where('method', '!=', 'cash')->sum('amount'); // none in this day; guards the assumption
        $refunds = (float) DB::table('returns')->whereIn('sale_id', $live)->sum('refund');
        $expenses = (float) DB::table('expenses')->where('shop_id', $id)->where('method', 'cash')->sum('amount');
        $purchases = (float) DB::table('purchases')->where('shop_id', $id)->where('method', 'cash')->where('status', 'received')->sum('amount');
        $supplierPaid = (float) DB::table('supplier_payments')->where('shop_id', $id)->where('method', 'cash')->sum('amount');
        $supplierBack = (float) DB::table('supplier_returns')->where('shop_id', $id)->where('settlement_method', 'cash')->sum('amount');
        $this->assertSame(0.0, $bank, 'this day uses cash only');

        return round(self::OPENING_CASH + $billed - $refunds - $expenses - $purchases - $supplierPaid + $supplierBack, 2);
    }

    private function expectedPayables(): float
    {
        $id = $this->shop->id;
        $credit = (float) DB::table('purchases')->where('shop_id', $id)->where('method', 'credit')->where('status', 'received')->whereNotNull('supplier_id')->sum('amount');
        $paid = (float) DB::table('supplier_payments')->where('shop_id', $id)->sum('amount');
        $back = (float) DB::table('supplier_returns')->where('shop_id', $id)->where('settlement_method', 'payable')->sum('amount');

        return round($credit - $paid - $back, 2);
    }

    /** a request that must have been accepted: no error status, no validation errors flashed */
    private function assertAccepted($r, string $what): void
    {
        $errors = method_exists($r, 'getSession') && $r->getSession() ? ($r->getSession()->get('errors')?->all() ?? []) : [];
        $this->assertTrue($r->status() < 400 && ! $errors, "{$what} refused: status {$r->status()} ".json_encode($errors).' '.substr((string) $r->getContent(), 0, 250));
    }

    private function ledgerDump(): string
    {
        return json_encode([
            'returns' => DB::table('returns')->get(['id', 'sale_id', 'qty', 'refund', 'applied_to_due', 'cost'])->all(),
            'sales' => DB::table('sales')->get(['id', 'total', 'discount', 'voided_at'])->all(),
            'sale_payments' => DB::table('sale_payments')->get(['sale_id', 'method', 'amount'])->all(),
        ]);
    }

    private function assertBooksBalance(string $after): void
    {
        $id = $this->shop->id;
        $actual = round((float) DB::table('shops')->where('id', $id)->value('cash_balance') + (float) DB::table('customers')->where('shop_id', $id)->sum('due'), 2);
        $this->assertEquals($this->expectedCashPlusDues(), $actual, "CASH + CUSTOMER DUES do not match the ledgers after {$after}: ".$this->ledgerDump());
        $this->assertGreaterThanOrEqual(-0.005, (float) DB::table('customers')->where('shop_id', $id)->min('due'), "a customer's due went negative after {$after}");
        $this->assertEquals($this->expectedPayables(), round((float) DB::table('suppliers')->where('shop_id', $id)->sum('payable'), 2), "SUPPLIER PAYABLES do not match the ledgers after {$after}");
    }

    // ---------------------------------------------------------------- operations

    private function pick(array $a)
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    private function stockOf(int $pid): int
    {
        return (int) DB::table('products')->where('id', $pid)->value('stock');
    }

    private function opSellCash(): bool
    {
        $pid = $this->pick($this->products);
        $price = (float) DB::table('products')->where('id', $pid)->value('price');
        $q = mt_rand(1, 3);
        $this->p3Sell([['product_id' => $pid, 'qty' => $q]], [['method' => 'cash', 'amount' => $price * $q]]);

        return true;
    }

    private function opSellOnCredit(): bool
    {
        $pid = $this->pick($this->products);
        $c = $this->pick($this->customers);
        $price = (float) DB::table('products')->where('id', $pid)->value('price');
        $q = mt_rand(2, 4);
        $paid = floor($price * $q * mt_rand(0, 6) / 10 / 10) * 10; // 0..60% in tens
        $this->p3Sell([['product_id' => $pid, 'qty' => $q]], $paid > 0 ? [['method' => 'cash', 'amount' => $paid]] : [], ['customer_phone' => $c['phone'], 'customer_name' => 'QA']);

        return true;
    }

    private function opVoid(): bool
    {
        // A bill whose customer has since paid money down WITHOUT picking that invoice is left out of the random day:
        // voiding it leaves that collection with no owner (finding D12, pinned by its own defect test below). Everything
        // else about voids stays in play.
        $live = array_values(array_filter($this->liveSaleIds(), function ($sid) {
            $cust = DB::table('sales')->where('id', $sid)->value('customer_id');

            return ! $cust || ! DB::table('payments')->where('customer_id', $cust)->whereNull('voided_at')->exists();
        }));
        if (! $live) {
            return false;
        }
        $sale = \App\Models\Sale::withoutGlobalScopes()->find($this->pick($live));
        $this->p3Void($sale)->assertSessionHas('success');

        return true;
    }

    private function opReturn(): bool
    {
        $rows = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->whereNull('s.voided_at')->where('s.shop_id', $this->shop->id)
            ->select('si.id', 'si.sale_id', 'si.qty')->get()
            ->filter(fn ($r) => (float) $r->qty - (float) DB::table('returns')->where('sale_item_id', $r->id)->sum('qty') >= 1)->values()->all();
        if (! $rows) {
            return false;
        }
        $r = $this->pick($rows);
        $res = $this->p3Return(\App\Models\Sale::withoutGlobalScopes()->find($r->sale_id), [['sale_item_id' => $r->id, 'qty' => 1, 'condition' => 'resalable']]);
        $this->assertSame(200, $res->status(), 'return refused: '.substr((string) $res->getContent(), 0, 200));

        return true;
    }

    private function opPurchase(string $method): bool
    {
        $pid = $this->pick($this->products);
        $q = mt_rand(1, 10);
        $body = ['amount' => 30 * $q, 'method' => $method, 'status' => 'received', 'product_id' => $pid, 'qty' => $q, 'cost' => 30];
        if ($method === 'credit' || mt_rand(0, 1)) {
            $body['supplier_id'] = $this->pick($this->suppliers);
        }
        $r = $this->p3As()->post('/app/purchases', $body);
        $this->assertAccepted($r, "purchase");

        return true;
    }

    private function opSupplierPayment(): bool
    {
        $owing = DB::table('suppliers')->where('shop_id', $this->shop->id)->where('payable', '>', 0)->get();
        if ($owing->isEmpty()) {
            return false;
        }
        $s = $owing->random();
        $amount = min((float) $s->payable, (float) mt_rand(10, 200));
        $this->p3As()->post("/app/suppliers/{$s->id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertSessionHasNoErrors();

        return true;
    }

    private function opSupplierReturn(): bool
    {
        $pid = $this->pick($this->products);
        if ($this->stockOf($pid) < 3) {
            return false;
        }
        $q = mt_rand(1, 3);
        $mode = $this->pick(['cash', 'payable']);
        $body = ['settlement_method' => $mode, 'reason' => 'QA', 'lines' => [['product_id' => $pid, 'qty' => $q, 'amount' => 30 * $q]]];
        if ($mode === 'payable') {
            $body['supplier_id'] = $this->pick($this->suppliers);
        }
        $r = $this->p3As()->postJson('/app/supplier-returns', $body);
        $this->assertAccepted($r, "supplier return");

        return true;
    }

    private function opExpense(): bool
    {
        $r = $this->p3As()->post('/app/expenses', ['amount' => mt_rand(10, 100), 'method' => 'cash', 'memo' => 'QA']);
        $this->assertAccepted($r, "expense");

        return true;
    }

    private function opCustomerPayment(): bool
    {
        $owing = DB::table('customers')->where('shop_id', $this->shop->id)->where('due', '>', 0)->get();
        if ($owing->isEmpty()) {
            return false;
        }
        $c = $owing->random();
        $amount = min((float) $c->due, (float) mt_rand(10, 150));
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => $amount, 'method' => 'cash'])->assertSessionHasNoErrors();

        return true;
    }

    /** @return array<string,array{0:int}> */
    public static function seeds(): array
    {
        return ['seed 11' => [11], 'seed 12' => [12], 'seed 13' => [13]];
    }

    #[DataProvider('seeds')]
    public function test_cash_dues_and_payables_equal_what_the_ledgers_say_after_every_step_of_a_random_day(int $seed): void
    {
        mt_srand($seed);
        $this->assertBooksBalance('opening');
        $ops = ['sellCash', 'sellCash', 'sellOnCredit', 'sellOnCredit', 'void', 'return', 'return', 'purchaseCash', 'purchaseCredit', 'supplierPayment', 'supplierReturn', 'expense', 'customerPayment', 'customerPayment'];
        $done = [];
        for ($i = 1; $i <= 40; $i++) {
            $op = $ops[mt_rand(0, count($ops) - 1)];
            $ok = match ($op) {
                'sellCash' => $this->opSellCash(),
                'sellOnCredit' => $this->opSellOnCredit(),
                'void' => $this->opVoid(),
                'return' => $this->opReturn(),
                'purchaseCash' => $this->opPurchase('cash'),
                'purchaseCredit' => $this->opPurchase('credit'),
                'supplierPayment' => $this->opSupplierPayment(),
                'supplierReturn' => $this->opSupplierReturn(),
                'expense' => $this->opExpense(),
                'customerPayment' => $this->opCustomerPayment(),
            };
            if ($ok) {
                $done[$op] = ($done[$op] ?? 0) + 1;
                $this->assertBooksBalance("step {$i} ({$op}, seed {$seed})");
            }
        }
        $this->assertGreaterThanOrEqual(6, count($done), 'at least six kinds of money movement happened: '.json_encode($done));
        $this->assertGreaterThanOrEqual(25, array_sum($done), 'a real day, not a vacuous one: '.json_encode($done));
    }

    // ---------------------------------------------------------------- the profit report against raw line math

    /** the per-bill profit the checkout stores must equal the arithmetic of its own lines */
    public function test_every_stored_bill_profit_equals_the_arithmetic_of_its_lines(): void
    {
        mt_srand(21);
        for ($i = 0; $i < 12; $i++) {
            $mt = mt_rand(0, 2);
            $mt === 0 ? $this->opSellCash() : ($mt === 1 ? $this->opSellOnCredit() : $this->p3Sell(
                [['product_id' => $this->products[0], 'qty' => 2, 'discount' => 7]], [['method' => 'cash', 'amount' => 183]], ['discount' => 10]
            ));
        }

        $wrong = [];
        foreach (DB::table('sales')->where('shop_id', $this->shop->id)->get() as $s) {
            $lines = DB::table('sale_items')->where('sale_id', $s->id)->get();
            $expected = round($lines->sum(fn ($l) => $l->qty * $l->price - $l->discount - $l->qty * $l->cost) - (float) $s->discount, 2);
            if (abs($expected - (float) $s->profit) > 0.011) {
                $wrong[] = "bill {$s->invoice_no}: stored profit {$s->profit}, lines say {$expected}";
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_the_profit_report_equals_stored_profit_minus_refunds_plus_returned_cost_for_live_bills_only(): void
    {
        mt_srand(31);
        for ($i = 0; $i < 10; $i++) {
            $this->opSellCash();
            $this->opSellOnCredit();
        }
        for ($i = 0; $i < 6; $i++) {
            $this->opReturn();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->opExpense();
        }

        $live = $this->liveSaleIds();
        $profit = (float) DB::table('sales')->whereIn('id', $live)->sum('profit');
        $refund = (float) DB::table('returns')->whereIn('sale_id', $live)->sum('refund');
        $cost = (float) DB::table('returns')->whereIn('sale_id', $live)->sum('cost');
        $exp = (float) DB::table('expenses')->where('shop_id', $this->shop->id)->sum('amount');

        Tenancy::clear();
        Tenancy::set($this->shop->id);
        $d = now()->toDateString();
        $stats = Reports::rangeStats($d, $d);

        $this->assertEqualsWithDelta($profit - $refund + $cost, $stats['grossProfit'], 0.02, 'gross profit');
        $this->assertEqualsWithDelta($profit - $refund + $cost - $exp, $stats['net'], 0.02, 'net profit after expenses');
        $this->assertEqualsWithDelta((float) DB::table('sales')->whereIn('id', $live)->sum('total') - $refund, $stats['salesAmt'], 0.02, 'net sales');
        $this->assertSame(count($live), $stats['count'], 'bill count');
    }

    /**
     * DEFECT D12 (owner decision). A credit bill of 150 (10 paid, 140 due); the customer then pays 100 at the due screen
     * without choosing an invoice; the bill is voided. The customer owes nothing now — but the 100 they paid is still
     * in the drawer and is recorded nowhere as theirs (due clamps at 0, advance stays 0). Their money has no owner.
     */
    #[Group('phase3_defect')]
    public function test_DEFECT_money_collected_against_a_credit_bill_that_is_later_voided_must_not_vanish(): void
    {
        $c = $this->p3Customer('01733333333');
        $sale = $this->p3Sell([['product_id' => $this->products[0], 'qty' => 2]], [['method' => 'cash', 'amount' => 10]], ['customer_phone' => '01733333333', 'customer_name' => 'QA']);
        $this->assertEquals(190.0, (float) DB::table('customers')->where('id', $c->id)->value('due'));
        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 100, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->p3Void($sale)->assertSessionHas('success');

        $due = (float) DB::table('customers')->where('id', $c->id)->value('due');
        $advance = (float) DB::table('customers')->where('id', $c->id)->value('advance_balance');
        $this->assertEquals(0.0, $due, 'the voided bill leaves no due');
        $this->assertEquals(100.0, $advance, 'the 100 the customer handed over must be theirs (store credit), not silently kept');
    }

    /**
     * A voided bill is removed from sales, so a refund that was paid against it earlier must not keep reducing the
     * profit. (Void effects net to zero: the sale leaves the books and so must its returns.)
     */
    #[Group('phase3_defect')]
    public function test_DEFECT_returns_of_a_voided_bill_must_not_keep_reducing_the_profit_report(): void
    {
        $sale = $this->p3Sell([['product_id' => $this->products[0], 'qty' => 2]], [['method' => 'cash', 'amount' => 200]]);
        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']])->assertOk();
        $this->p3Void($sale)->assertSessionHas('success');

        Tenancy::clear();
        Tenancy::set($this->shop->id);
        $d = now()->toDateString();
        $stats = Reports::rangeStats($d, $d);

        $this->assertEquals(0.0, round($stats['grossSales'], 2), 'the voided bill is not a sale');
        $this->assertEquals(0.0, round($stats['returnsTotal'], 2), 'and its refund is not a return of the live books');
        $this->assertEquals(0.0, round($stats['grossProfit'], 2), 'nothing was really sold: profit is zero, not negative');
        $this->assertEquals(self::OPENING_CASH, (float) DB::table('shops')->where('id', $this->shop->id)->value('cash_balance'), 'cash is back at opening (this part is correct)');
    }
}
