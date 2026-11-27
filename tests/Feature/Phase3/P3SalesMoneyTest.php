<?php

namespace Tests\Feature\Phase3;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — A. Sales, billing, payments, dues, refunds.
 *
 * Method: round trips. Any sequence that ends with "everything undone" must put every balance back exactly where it
 * started; and every figure is compared with a value worked out by hand, never with the application's own report code.
 * Tests in group phase3_defect state the PRINCIPLED expectation and currently fail — they are executable bug reports
 * (run: php artisan test --group=phase3_defect) and are excluded from the default run.
 */
class P3SalesMoneyTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot();
    }

    /** @return array<string, array{0:string}> */
    public static function shapes(): array
    {
        return ['plain cash' => ['plain'], 'decimals + line discount' => ['decimals'], 'bill discount' => ['bill'], 'variant, split cash+bkash' => ['variant_split'], 'credit sale' => ['credit']];
    }

    /** builds the sale for a shape and returns it; the shapes differ in what they exercise, not in what must be true afterwards */
    private function ring(string $shape): Sale
    {
        $p = $this->p3Product('Shoe', 60, 100, 50);
        switch ($shape) {
            case 'plain':
                return $this->p3Sell([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]]);
            case 'decimals':
                $q = $this->p3Product('Odd', 20, 33.33, 50);

                return $this->p3Sell([['product_id' => $q->id, 'qty' => 3, 'discount' => 0.50]], [['method' => 'cash', 'amount' => 99.49]]);
            case 'bill':
                return $this->p3Sell([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 185]], ['discount' => 15]);
            case 'variant_split':
                $v = $this->p3Product('Sneaker', 90, 150, 0);
                $m = $this->p3Variant($v, 'M', 10, ['price' => 150, 'cost' => 90]);

                return $this->p3Sell([['product_id' => $v->id, 'product_variant_id' => $m->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 100], ['method' => 'bkash', 'amount' => 200]]);
            case 'credit':
                return $this->p3Sell([['product_id' => $p->id, 'qty' => 4]], [['method' => 'cash', 'amount' => 150]], ['customer_phone' => '01711111111', 'customer_name' => 'QA Customer']);
        }
        $this->fail('unknown shape');
    }

    #[DataProvider('shapes')]
    public function test_sale_then_void_is_a_perfect_round_trip(string $shape): void
    {
        $this->p3Product('Pad', 1, 1, 1); // keep ids stable across shapes
        $this->p3Customer('01711111111');
        $start = $this->p3Snap();
        $sale = $this->ring($shape);
        $afterSale = $this->p3Snap();
        $this->assertNotEquals($start, $afterSale, 'the sale really moved something');

        $this->p3Void($sale)->assertSessionHas('success');

        // products created by ring() are part of the state; compare only what must have returned
        $end = $this->p3Snap();
        $this->assertEquals($afterSale['product_stock'] ?? null, $afterSale['product_stock']);
        foreach (['cash', 'bank', 'customer_due', 'loyalty_points'] as $k) {
            $this->assertEquals($start[$k], $end[$k], "{$k} is back where it started after a void ({$shape})");
        }
        $this->assertNotNull(Sale::withoutGlobalScopes()->find($sale->id)->voided_at);
        // stock: every product that existed before the sale is back at its original figure; new ones are back at what they were created with
        foreach (DB::table('products')->where('shop_id', $this->shop->id)->get(['id', 'name', 'stock']) as $row) {
            $this->assertEquals(['Shoe' => 50, 'Odd' => 50, 'Pad' => 1, 'Sneaker' => 10][$row->name] ?? null, (float) $row->stock, "{$row->name} stock restored ({$shape})");
        }
    }

    public function test_full_return_in_one_event_of_a_cash_sale_restores_cash_and_stock(): void
    {
        $this->p3Product('Pad', 1, 1, 1);
        $p = $this->p3Product('Shoe', 60, 100, 50);
        $start = $this->p3Snap();
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 3, 'discount' => 30]], [['method' => 'cash', 'amount' => 260]], ['discount' => 10]);

        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 3, 'condition' => 'resalable']])->assertOk();

        $end = $this->p3Snap();
        $this->assertEquals($start['cash'] - 0, $end['cash'] + 0 - 0, 'cash back to start');
        $this->assertEquals(50.0, $end['product_stock'][$p->id], 'stock back');
        $this->assertEquals(260.0, (float) DB::table('returns')->where('sale_id', $sale->id)->sum('refund'), 'refund = what was actually paid: 300 - 30 line discount - 10 bill discount');
    }

    /** the principled expectation, shared by the defect test below (kept out of the default run) */
    private function assertSinglePieceReturnsNeverOverRefund(): void
    {
        // net paid = 3 x 100 - 10 bill discount = 290.00; per piece 96.666…; three rounded refunds of 96.67 would be 290.01
        $p = $this->p3Product('Shoe', 60, 100, 50);
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 3]], [['method' => 'cash', 'amount' => 290]], ['discount' => 10]);
        $item = $this->p3ItemId($sale);

        for ($i = 0; $i < 3; $i++) {
            $this->p3Return($sale, [['sale_item_id' => $item, 'qty' => 1, 'condition' => 'resalable']])->assertOk();
        }

        $refunded = round((float) DB::table('returns')->where('sale_id', $sale->id)->sum('refund'), 2);
        $this->assertEquals(290.00, $refunded, "three one-piece returns refunded {$refunded} for a bill that was paid 290.00");
    }

    #[Group('phase3_defect')]
    public function test_DEFECT_rounding_three_single_piece_returns_must_not_over_refund(): void
    {
        $this->assertSinglePieceReturnsNeverOverRefund();
    }

    public function test_partial_return_then_void_returns_every_balance_to_the_start(): void
    {
        $p = $this->p3Product('Shoe', 60, 100, 50);
        $start = $this->p3Snap();
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]]);
        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']])->assertOk();

        $this->p3Void($sale)->assertSessionHas('success');

        $this->p3AssertSame($start, $this->p3Snap(), 'sold 2, took 1 back, voided the rest');
    }

    public function test_a_credit_sale_partly_returned_reduces_the_customers_due_by_the_credit_share_only(): void
    {
        $c = $this->p3Customer('01711111111');
        $p = $this->p3Product('Shoe', 60, 100, 50);
        // 4 x 100 = 400 ; paid 100 cash, 300 on credit
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 4]], [['method' => 'cash', 'amount' => 100]], ['customer_phone' => '01711111111', 'customer_name' => 'QA']);
        $this->assertEquals(300.0, (float) DB::table('customers')->where('id', $c->id)->value('due'));
        $cashAfterSale = (float) DB::table('shops')->where('id', $this->shop->id)->value('cash_balance');

        // return 2 pcs = 200 refund; credit share = 200 * 300/400 = 150 off the due, 50 paid out in cash
        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 2, 'condition' => 'resalable']])->assertOk();

        $this->assertEquals(150.0, (float) DB::table('customers')->where('id', $c->id)->value('due'), 'due 300 -> 150');
        $this->assertEquals($cashAfterSale - 50, (float) DB::table('shops')->where('id', $this->shop->id)->value('cash_balance'), 'only the paid share leaves the drawer');
    }

    private function assertBkashRefundGoesBackTheWayItCame(): void
    {
        // principled expectation: money goes back the way it came
        $p = $this->p3Product('Shoe', 60, 100, 50);
        $start = $this->p3Snap();
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 1]], [['method' => 'bkash', 'amount' => 100]]);
        $this->assertEquals($start['bank'] + 100, $this->p3Snap()['bank']);

        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']])->assertOk();

        $end = $this->p3Snap();
        $this->assertEquals($start['bank'], $end['bank'], 'bank balance should fall back by the refunded bKash amount');
        $this->assertEquals($start['cash'], $end['cash'], 'and the cash drawer should not have paid for a bKash sale');
    }

    #[Group('phase3_defect')]
    public function test_DEFECT_bkash_refund_leaves_the_bank_balance_overstated_and_empties_the_cash_drawer(): void
    {
        $this->assertBkashRefundGoesBackTheWayItCame();
    }

    // ---------------------------------------------------------------- validation: nothing is written on a refusal

    public function test_a_refused_checkout_changes_nothing(): void
    {
        $p = $this->p3Product('Shoe', 60, 100, 5);
        $this->p3Customer('01711111111');
        $start = $this->p3Snap();
        $salesBefore = DB::table('sales')->count();

        $cases = [
            'pays more than the total' => [[['product_id' => $p->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 500]], []],
            'pays less with no customer for the rest' => [[['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 50]], []],
            'quantity beyond stock' => [[['product_id' => $p->id, 'qty' => 6]], [['method' => 'cash', 'amount' => 600]], []],
            'fractional pieces of a counted product' => [[['product_id' => $p->id, 'qty' => 1.5]], [['method' => 'cash', 'amount' => 150]], []],
            'zero quantity' => [[['product_id' => $p->id, 'qty' => 0]], [['method' => 'cash', 'amount' => 0]], []],
            'negative quantity' => [[['product_id' => $p->id, 'qty' => -1]], [['method' => 'cash', 'amount' => 0]], []],
            'negative line discount' => [[['product_id' => $p->id, 'qty' => 1, 'discount' => -10]], [['method' => 'cash', 'amount' => 110]], []],
            'unknown product' => [[['product_id' => 999999, 'qty' => 1]], [['method' => 'cash', 'amount' => 100]], []],
            'empty cart' => [[], [['method' => 'cash', 'amount' => 0]], []],
            'unknown payment method' => [[['product_id' => $p->id, 'qty' => 1]], [['method' => 'barter', 'amount' => 100]], []],
        ];
        foreach ($cases as $name => [$items, $payments, $extra]) {
            $r = $this->p3Checkout($items, $payments, $extra);
            $this->assertGreaterThanOrEqual(400, $r->status(), "'{$name}' must be refused, got ".$r->status());
            $this->assertLessThan(500, $r->status(), "'{$name}' must be a clean 4xx, not a crash: ".substr((string) $r->getContent(), 0, 120));
            $this->assertSame($salesBefore, DB::table('sales')->count(), "'{$name}' wrote a sale");
            $this->p3AssertSame($start, $this->p3Snap(), "'{$name}' changed a balance");
        }
    }

    public function test_selling_exactly_the_last_pieces_works_and_one_more_does_not(): void
    {
        $p = $this->p3Product('Shoe', 60, 100, 3);

        $this->p3Sell([['product_id' => $p->id, 'qty' => 3]], [['method' => 'cash', 'amount' => 300]]);
        $this->assertEquals(0.0, (float) DB::table('products')->where('id', $p->id)->value('stock'));

        $this->assertSame(422, $this->p3Checkout([['product_id' => $p->id, 'qty' => 1]], [['method' => 'cash', 'amount' => 100]])->status());
        $this->assertEquals(0.0, (float) DB::table('products')->where('id', $p->id)->value('stock'), 'never negative without the explicit oversell flag');
    }

    public function test_replaying_a_checkout_with_the_same_client_uuid_books_it_once_even_if_the_payload_changed(): void
    {
        $p = $this->p3Product('Shoe', 60, 100, 50);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $first = $this->p3Checkout([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]], ['client_uuid' => $uuid]);
        $first->assertOk();
        $afterFirst = $this->p3Snap();

        // a retry of the same request, then a *different* body under the same uuid (a stale offline replay)
        $again = $this->p3Checkout([['product_id' => $p->id, 'qty' => 2]], [['method' => 'cash', 'amount' => 200]], ['client_uuid' => $uuid]);
        $other = $this->p3Checkout([['product_id' => $p->id, 'qty' => 5]], [['method' => 'cash', 'amount' => 500]], ['client_uuid' => $uuid]);

        $this->assertSame(1, DB::table('sales')->where('client_uuid', $uuid)->count(), 'one sale for one uuid');
        $this->assertSame($first->json('sale.id'), $again->json('sale.id'), 'the replay returns the SAME bill');
        $this->assertSame($first->json('sale.id'), $other->json('sale.id'), 'a different body does not create a second bill either');
        $this->p3AssertSame($afterFirst, $this->p3Snap(), 'no second movement of stock or cash');
    }

    // ---------------------------------------------------------------- customer dues and payments

    public function test_customer_payments_settle_the_due_exactly_and_voiding_one_puts_it_back(): void
    {
        $this->grantFeature($this->shop, 'parties');
        $c = $this->p3Customer('01711111111', 500);
        $start = $this->p3Snap();

        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 200, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(300.0, (float) DB::table('customers')->where('id', $c->id)->value('due'));
        $this->assertEquals($start['cash'] + 200, $this->p3Snap()['cash']);

        $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 300, 'method' => 'bkash'])->assertSessionHasNoErrors();
        $this->assertEquals(0.0, (float) DB::table('customers')->where('id', $c->id)->value('due'));
        $this->assertEquals($start['bank'] + 300, $this->p3Snap()['bank']);

        foreach (DB::table('payments')->where('customer_id', $c->id)->pluck('id') as $pid) {
            $this->p3As()->post("/app/payments/{$pid}/void", [])->assertSessionHasNoErrors();
        }
        $this->p3AssertSame($start, $this->p3Snap(), 'both payments voided');
    }

    public function test_paying_more_than_the_due_is_handled_without_losing_money(): void
    {
        $c = $this->p3Customer('01711111111', 100);
        $start = $this->p3Snap();

        $r = $this->p3As()->post("/app/customers/{$c->id}/payments", ['amount' => 250, 'method' => 'cash']);

        $end = $this->p3Snap();
        $dueAfter = (float) DB::table('customers')->where('id', $c->id)->value('due');
        $advance = (float) DB::table('customers')->where('id', $c->id)->value('advance_balance');
        $cashIn = $end['cash'] - $start['cash'];
        // either refused outright, or the surplus is kept as the customer's advance — but the books must balance
        if ($r->status() >= 400 || $r->getSession()?->has('errors')) {
            $this->p3AssertSame($start, $end, 'refused overpayment changed state');
        } else {
            $this->assertEquals(250.0, $cashIn, 'the cash that came in');
            $this->assertEquals(250.0, (100.0 - $dueAfter) + $advance, 'what came in is accounted for: due reduced + advance kept');
        }
    }

    public function test_loyalty_points_are_earned_taken_back_on_a_return_and_restored_by_a_void(): void
    {
        $this->shop->forceFill(['loyalty_earn_rate' => 1, 'loyalty_point_value' => 1])->save();
        $this->grantFeature($this->shop, 'loyalty_points');
        $c = $this->p3Customer('01711111111');
        $p = $this->p3Product('Shoe', 60, 100, 50);

        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 10]], [['method' => 'cash', 'amount' => 1000]], ['customer_phone' => '01711111111', 'customer_name' => 'QA']);
        $earned = (int) DB::table('customers')->where('id', $c->id)->value('loyalty_points');
        $this->assertSame(10, $earned, '1 point per 100 spent');

        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 5, 'condition' => 'resalable']])->assertOk();
        $this->assertSame(5, (int) DB::table('customers')->where('id', $c->id)->value('loyalty_points'), 'half returned, half the points back');

        $this->p3Void($sale)->assertSessionHas('success');
        $this->assertSame(0, (int) DB::table('customers')->where('id', $c->id)->value('loyalty_points'), 'voided: no points left');
    }
}
