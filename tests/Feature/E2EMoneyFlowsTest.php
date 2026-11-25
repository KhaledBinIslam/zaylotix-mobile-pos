<?php

namespace Tests\Feature;

use App\Http\Controllers\App\AccountsController;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * REAL end-to-end write tests for the money / parties / admin features, on a
 * Baby-Park-shaped fixture (main shop + one branch, shared_stock_pool).
 *
 * Every flow goes through the REAL HTTP route as the real actor and then
 * asserts EVERY layer it should have touched: the shop's cash/bank balance
 * (and that the SIBLING branch's did NOT move), supplier payable, customer
 * due/advance, the document/ledger rows with their before/after snapshots,
 * variant AND product stock in BOTH branches, stockValue() equal in both,
 * the activity log, and finally the Accounts page's own figures against an
 * independently recomputed value. Khaled's demand: "prove every feature
 * really updates all the data" — not just that the code runs.
 */
class E2EMoneyFlowsTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $main;

    private Shop $branch;

    private User $mainOwner;

    private User $branchOwner;

    private const FEATURES = ['shared_catalog', 'shared_stock_pool', 'product_variants', 'suppliers', 'accounts', 'cashier_management', 'online_business', 'purchases', 'expenses', 'damages', 'returns', 'parties'];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->main, $this->mainOwner] = $this->createShopWithOwner(['cash_balance' => 100000, 'bank_balance' => 50000]);
        [$this->branch, $this->branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $this->main->id, 'cash_balance' => 20000, 'bank_balance' => 8000]);
        foreach ([$this->main, $this->branch] as $shop) {
            foreach (self::FEATURES as $f) {
                $this->grantFeature($shop, $f);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: Product, 1: Product, 2: array<string,ProductVariant>, 3: array<string,ProductVariant>} master, branch copy, master variants by size, branch variants by size */
    private function article(string $name, array $sizeStock, float $cost = 100, ?float $variantCost = null, ?Supplier $supplier = null): array
    {
        $master = Product::create(['shop_id' => $this->main->id, 'name' => $name, 'cost' => $cost, 'price' => $cost * 2, 'stock' => 0, 'supplier_id' => $supplier?->id]);
        foreach ($sizeStock as $size => $stock) {
            // the created() hook mirrors each size into the branch with the same stock
            ProductVariant::create(['shop_id' => $this->main->id, 'product_id' => $master->id, 'size' => (string) $size, 'color' => 'BLACK', 'stock' => $stock, 'cost' => $variantCost]);
        }
        $copy = Product::withoutGlobalScopes()->where('shop_id', $this->branch->id)->where('master_product_id', $master->id)->firstOrFail();
        $sum = array_sum($sizeStock);
        Product::withoutGlobalScopes()->whereIn('id', [$master->id, $copy->id])->update(['stock' => $sum]);

        $mv = ProductVariant::withoutGlobalScopes()->where('product_id', $master->id)->get()->keyBy('size')->all();
        $bv = ProductVariant::withoutGlobalScopes()->where('product_id', $copy->id)->get()->keyBy('size')->all();

        return [$master->fresh(), $copy->fresh(), $mv, $bv];
    }

    private function stockOf(int $variantId): float
    {
        return (float) ProductVariant::withoutGlobalScopes()->whereKey($variantId)->value('stock');
    }

    private function productStock(int $productId): float
    {
        return (float) Product::withoutGlobalScopes()->whereKey($productId)->value('stock');
    }

    private function cash(Shop $s): float
    {
        return (float) Shop::withoutGlobalScopes()->whereKey($s->id)->value('cash_balance');
    }

    private function bank(Shop $s): float
    {
        return (float) Shop::withoutGlobalScopes()->whereKey($s->id)->value('bank_balance');
    }

    /** The real Accounts stock-value formula (private), run as each shop. */
    private function stockValueAs(Shop $shop): float
    {
        Tenancy::forgetShopCache();
        Tenancy::set($shop->id);
        $m = new \ReflectionMethod(AccountsController::class, 'stockValue');
        $m->setAccessible(true);
        $v = (float) $m->invoke(new AccountsController);
        Tenancy::set(null);
        Tenancy::forgetShopCache();

        return $v;
    }

    private function assertPoolEqual(string $label, array $variantPairs, array $productPair): void
    {
        foreach ($variantPairs as $size => [$a, $b]) {
            $this->assertEquals($this->stockOf($a->id), $this->stockOf($b->id), "{$label}: variant {$size} stock identical in both branches");
        }
        $this->assertEquals($this->productStock($productPair[0]->id), $this->productStock($productPair[1]->id), "{$label}: product stock identical in both branches");
        $this->assertEqualsWithDelta($this->stockValueAs($this->main), $this->stockValueAs($this->branch), 0.001, "{$label}: stock value identical in both branches");
    }

    private function logged(string $action, Shop $shop): bool
    {
        return DB::table('activity_logs')->where('action', $action)->where('shop_id', $shop->id)->exists();
    }

    private function supplier(Shop $shop, string $name, float $payable): Supplier
    {
        return Supplier::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => $name, 'payable' => $payable]);
    }

    private function payable(Supplier $s): float
    {
        return (float) Supplier::withoutGlobalScopes()->whereKey($s->id)->value('payable');
    }

    // ================================================== 1. SUPPLIER RETURN (stock screen payload)

    public function test_supplier_return_one_variant_against_payable_updates_every_layer_in_both_branches(): void
    {
        $bulu = $this->supplier($this->main, 'Bulu', 5000);
        [$m, $c, $mv, $bv] = $this->article('L-10001', ['40' => 10, '41' => 6], 100, 120, $bulu);

        $cashBefore = [$this->cash($this->main), $this->cash($this->branch)];
        $bankBefore = [$this->bank($this->main), $this->bank($this->branch)];

        $this->actingAs($this->mainOwner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier_id' => $bulu->id, 'settlement_method' => 'payable', 'reason' => 'defective',
            'lines' => [['product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 3, 'amount' => 360]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        // stock: variant + product, BOTH branches, by exactly the returned qty, once
        $this->assertEquals(7, $this->stockOf($mv['40']->id));
        $this->assertEquals(7, $this->stockOf($bv['40']->id));
        $this->assertEquals(6, $this->stockOf($mv['41']->id), 'other size untouched');
        $this->assertEquals(13, $this->productStock($m->id));
        $this->assertEquals(13, $this->productStock($c->id));
        $this->assertPoolEqual('after return', ['40' => [$mv['40'], $bv['40']], '41' => [$mv['41'], $bv['41']]], [$m, $c]);
        $this->assertEqualsWithDelta(13 * 120, $this->stockValueAs($this->branch), 0.001, 'stock value dropped by exactly 3 x 120 in the OTHER branch too');

        // money: payable settlement touches NO cash/bank anywhere
        $this->assertEquals(4640, $this->payable($bulu));
        $this->assertEquals($cashBefore, [$this->cash($this->main), $this->cash($this->branch)]);
        $this->assertEquals($bankBefore, [$this->bank($this->main), $this->bank($this->branch)]);

        // document + audit trail
        $this->assertDatabaseHas('supplier_returns', ['shop_id' => $this->main->id, 'supplier_id' => $bulu->id, 'product_variant_id' => $mv['40']->id, 'qty' => 3, 'amount' => 360, 'settlement_method' => 'payable']);
        $this->assertTrue($this->logged('supplier.return', $this->main));
    }

    public function test_supplier_return_of_a_whole_article_in_one_submission_with_cash_settlement(): void
    {
        $bulu = $this->supplier($this->main, 'Bulu', 0);
        [$m, $c, $mv, $bv] = $this->article('L-10002', ['38' => 4, '39' => 5, '40' => 3], 100, 100, $bulu);

        $this->actingAs($this->mainOwner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier_id' => $bulu->id, 'settlement_method' => 'cash',
            'lines' => [
                ['product_id' => $m->id, 'product_variant_id' => $mv['38']->id, 'qty' => 4, 'amount' => 400],
                ['product_id' => $m->id, 'product_variant_id' => $mv['39']->id, 'qty' => 2, 'amount' => 200],
                // size 40 left blank on the sheet -> not sent
            ],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0, $this->stockOf($mv['38']->id));
        $this->assertEquals(0, $this->stockOf($bv['38']->id));
        $this->assertEquals(3, $this->stockOf($mv['39']->id));
        $this->assertEquals(3, $this->stockOf($bv['39']->id));
        $this->assertEquals(3, $this->stockOf($bv['40']->id), 'untouched size untouched in the branch too');
        $this->assertEquals(6, $this->productStock($c->id));
        $this->assertPoolEqual('whole article', ['38' => [$mv['38'], $bv['38']], '39' => [$mv['39'], $bv['39']], '40' => [$mv['40'], $bv['40']]], [$m, $c]);

        $this->assertEquals(100000 + 600, $this->cash($this->main), 'the 600 lands in the returning shop cash');
        $this->assertEquals(20000, $this->cash($this->branch), 'the sibling branch cash does not move');
        $this->assertEquals(0, $this->payable($bulu), 'cash settlement never touches payable');
        $this->assertSame(2, SupplierReturn::withoutGlobalScopes()->count());
    }

    public function test_supplier_return_below_zero_is_refused_then_goes_through_with_force_on_bank_settlement(): void
    {
        $bulu = $this->supplier($this->main, 'Bulu', 1000);
        [$m, $c, $mv, $bv] = $this->article('L-10003', ['40' => 2], 100, 100, $bulu);
        $payload = ['supplier_id' => $bulu->id, 'settlement_method' => 'bank', 'lines' => [['product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 5, 'amount' => 500]]];

        $this->actingAs($this->mainOwner, 'web')->post(route('app.supplierReturns.store'), $payload)->assertSessionHasErrors('force');
        $this->assertEquals(2, $this->stockOf($mv['40']->id), 'refused: nothing moved');
        $this->assertEquals(2, $this->stockOf($bv['40']->id));
        $this->assertEquals(50000, $this->bank($this->main));
        $this->assertSame(0, SupplierReturn::withoutGlobalScopes()->count(), 'refused: no document');

        $this->actingAs($this->mainOwner, 'web')->post(route('app.supplierReturns.store'), $payload + ['force' => true])->assertSessionHasNoErrors();
        $this->assertEquals(-3, $this->stockOf($mv['40']->id));
        $this->assertEquals(-3, $this->stockOf($bv['40']->id), 'forced negative lands in the pool exactly once');
        $this->assertEquals(-3, $this->productStock($c->id));
        $this->assertEquals(50000 + 500, $this->bank($this->main));
        $this->assertEquals(100000, $this->cash($this->main));
        $this->assertEquals(1000, $this->payable($bulu));
        $this->assertEqualsWithDelta($this->stockValueAs($this->main), $this->stockValueAs($this->branch), 0.001);
    }

    public function test_supplier_return_done_at_the_branch_keeps_the_pool_equal_and_pays_the_branch(): void
    {
        $sujon = $this->supplier($this->branch, 'Sujon', 3000);
        [$m, $c, $mv, $bv] = $this->article('L-10004', ['40' => 9], 100, 100);

        $this->actingAs($this->branchOwner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier_id' => $sujon->id, 'settlement_method' => 'payable',
            'lines' => [['product_id' => $c->id, 'product_variant_id' => $bv['40']->id, 'qty' => 4, 'amount' => 400]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5, $this->stockOf($mv['40']->id));
        $this->assertEquals(5, $this->stockOf($bv['40']->id));
        $this->assertEquals(2600, $this->payable($sujon), 'the BRANCH supplier ledger, not the main one');
        $this->assertTrue($this->logged('supplier.return', $this->branch));
        $this->assertFalse($this->logged('supplier.return', $this->main));
        $this->assertPoolEqual('branch return', ['40' => [$mv['40'], $bv['40']]], [$m, $c]);
    }

    // ================================================== 2. PURCHASE + SUPPLIER PAYMENT

    public function test_a_credit_purchase_raises_payable_and_stock_in_both_branches_and_a_payment_lowers_payable_and_cash(): void
    {
        $bulu = $this->supplier($this->main, 'Bulu', 1000);
        [$m, $c, $mv, $bv] = $this->article('L-10005', ['40' => 5], 100, 100, $bulu);

        $cash0 = $this->cash($this->main);

        // ---- credit purchase: 10 pcs, 3000 on credit, at the SAME unit cost
        $this->actingAs($this->mainOwner, 'web')->post(route('app.purchases.store'), [
            'supplier_id' => $bulu->id, 'amount' => 1000, 'method' => 'credit', 'memo' => 'bill 77',
            'product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 10, 'cost' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('purchases', ['shop_id' => $this->main->id, 'supplier_id' => $bulu->id, 'amount' => 1000, 'method' => 'credit', 'status' => 'received', 'product_variant_id' => $mv['40']->id, 'qty' => 10]);
        $this->assertEquals(2000, $this->payable($bulu), 'payable raised by the credit amount');
        $this->assertEquals($cash0, $this->cash($this->main), 'a credit purchase moves no cash');
        $this->assertEquals(15, $this->stockOf($mv['40']->id));
        $this->assertEquals(15, $this->stockOf($bv['40']->id), 'the branch copy gets the same units');
        $this->assertEquals(15, $this->productStock($m->id));
        $this->assertEquals(15, $this->productStock($c->id));
        $this->assertPoolEqual('after purchase', ['40' => [$mv['40'], $bv['40']]], [$m, $c]);
        $this->assertTrue($this->logged('purchase.record', $this->main));

        // ---- supplier payment: cash 600, then bkash 200 (counts as bank)
        $this->actingAs($this->mainOwner, 'web')->post(route('app.suppliers.payments.store', $bulu->id), ['amount' => 600, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(1400, $this->payable($bulu));
        $this->assertEquals($cash0 - 600, $this->cash($this->main));
        $this->assertEquals(20000, $this->cash($this->branch), 'sibling cash untouched');
        $this->assertDatabaseHas('supplier_payments', ['shop_id' => $this->main->id, 'supplier_id' => $bulu->id, 'amount' => 600, 'payable_before' => 2000, 'payable_after' => 1400, 'method' => 'cash']);

        $this->actingAs($this->mainOwner, 'web')->post(route('app.suppliers.payments.store', $bulu->id), ['amount' => 200, 'method' => 'bkash'])->assertSessionHasNoErrors();
        $this->assertEquals(1200, $this->payable($bulu));
        $this->assertEquals(50000 - 200, $this->bank($this->main), 'bkash comes out of the bank balance');
        $this->assertDatabaseHas('supplier_payments', ['supplier_id' => $bulu->id, 'amount' => 200, 'payable_before' => 1400, 'payable_after' => 1200, 'method' => 'bkash']);
        $this->assertTrue($this->logged('supplier.payment', $this->main));

        // ---- over-payment refused, nothing moves
        $this->actingAs($this->mainOwner, 'web')->post(route('app.suppliers.payments.store', $bulu->id), ['amount' => 99999, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertEquals(1200, $this->payable($bulu));
        $this->assertSame(2, SupplierPayment::withoutGlobalScopes()->count());
    }

    public function test_a_cash_purchase_at_a_new_unit_cost_keeps_stock_value_identical_in_both_branches(): void
    {
        // the buying shop's variant cost changes (100 -> 150); the sibling's must follow,
        // or the two branches' stock value part ways (Baby Park's parity rule)
        [$m, $c, $mv, $bv] = $this->article('L-10006', ['40' => 4], 100, 100);

        $this->actingAs($this->mainOwner, 'web')->post(route('app.purchases.store'), [
            'amount' => 1500, 'method' => 'cash',
            'product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 6, 'cost' => 150,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(100000 - 1500, $this->cash($this->main), 'cash purchase pays from the buying shop');
        $this->assertEquals(20000, $this->cash($this->branch));
        $this->assertEquals(10, $this->stockOf($mv['40']->id));
        $this->assertEquals(10, $this->stockOf($bv['40']->id));
        $this->assertEqualsWithDelta($this->stockValueAs($this->main), $this->stockValueAs($this->branch), 0.001, 'same stock value in both branches after a purchase at a new cost');
    }

    // ================================================== 3. EXPENSE + DAMAGE

    public function test_expenses_move_cash_or_bank_of_the_spending_shop_only_and_show_in_accounts(): void
    {
        $this->actingAs($this->mainOwner, 'web')->post(route('app.expenses.store'), ['amount' => 700, 'method' => 'cash', 'memo' => 'rent', 'new_category_name' => 'Rent'])->assertSessionHasNoErrors();
        $this->assertEquals(100000 - 700, $this->cash($this->main));
        $this->assertDatabaseHas('expenses', ['shop_id' => $this->main->id, 'amount' => 700, 'method' => 'cash', 'memo' => 'rent']);
        $this->assertDatabaseHas('expense_categories', ['shop_id' => $this->main->id, 'name' => 'Rent']);
        $this->assertTrue($this->logged('expense.record', $this->main), 'an expense leaves an audit-trail row');

        $this->actingAs($this->mainOwner, 'web')->post(route('app.expenses.store'), ['amount' => 300, 'method' => 'bank', 'memo' => 'net bill'])->assertSessionHasNoErrors();
        $this->assertEquals(50000 - 300, $this->bank($this->main));
        $this->assertEquals(100000 - 700, $this->cash($this->main), 'the bank expense did not touch cash');

        // the branch is its own books
        $this->actingAs($this->branchOwner, 'web')->post(route('app.expenses.store'), ['amount' => 50, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(20000 - 50, $this->cash($this->branch));
        $this->assertEquals(100000 - 700, $this->cash($this->main), 'main cash unaffected by a branch expense');

        $page = $this->actingAs($this->mainOwner, 'web')->get(route('app.accounts'));
        $page->assertOk()->assertInertia(fn ($p) => $p->where('totalExpenses', fn ($v) => (float) $v === 1000.0)->where('cash', fn ($v) => (float) $v === 99300.0));

        // invalid input changes nothing
        $this->actingAs($this->mainOwner, 'web')->post(route('app.expenses.store'), ['amount' => 0, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertEquals(100000 - 700, $this->cash($this->main));
        $this->assertSame(3, Expense::withoutGlobalScopes()->count());
    }

    public function test_a_damage_removes_the_pooled_stock_from_both_branches_and_records_the_loss_at_cost(): void
    {
        [$m, $c, $mv, $bv] = $this->article('L-10007', ['40' => 8], 100, null); // variant cost null -> product cost 100

        $this->actingAs($this->mainOwner, 'web')->post(route('app.damages.store'), [
            'product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 3, 'reason' => 'torn',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5, $this->stockOf($mv['40']->id));
        $this->assertEquals(5, $this->stockOf($bv['40']->id));
        $this->assertEquals(5, $this->productStock($c->id));
        $this->assertDatabaseHas('damages', ['shop_id' => $this->main->id, 'product_variant_id' => $mv['40']->id, 'qty' => 3, 'loss' => 300]);
        $this->assertPoolEqual('after damage', ['40' => [$mv['40'], $bv['40']]], [$m, $c]);
        $this->assertTrue($this->logged('damage.record', $this->main), 'a damage leaves an audit-trail row');
        $this->actingAs($this->mainOwner, 'web')->get(route('app.accounts'))->assertInertia(fn ($p) => $p->where('totalDamage', fn ($v) => (float) $v === 300.0));

        // more than is on the shelf is refused and moves nothing
        $this->actingAs($this->mainOwner, 'web')->post(route('app.damages.store'), ['product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 50])->assertSessionHasErrors('qty');
        $this->assertEquals(5, $this->stockOf($bv['40']->id));
        $this->assertSame(1, Damage::withoutGlobalScopes()->count());
    }

    // ================================================== 4. CUSTOMER DUE COLLECTION

    private function customer(float $due, float $advance = 0): Customer
    {
        return Customer::withoutGlobalScopes()->create(['shop_id' => $this->main->id, 'name' => 'Mam', 'phone' => '01843974787', 'due' => $due, 'advance_balance' => $advance]);
    }

    public function test_collecting_a_due_updates_due_cash_and_the_payment_snapshot_exactly_once(): void
    {
        $cust = $this->customer(1000);
        $uuid = 'cc4f0f0e-6e53-4f3f-9a50-1f2f1c8c9a11';

        $this->actingAs($this->mainOwner, 'web')->post(route('app.payments.store', $cust->id), ['amount' => 400, 'method' => 'cash', 'client_uuid' => $uuid])->assertSessionHasNoErrors();
        $this->assertEquals(600, (float) $cust->fresh()->due);
        $this->assertEquals(100000 + 400, $this->cash($this->main));
        $this->assertDatabaseHas('payments', ['customer_id' => $cust->id, 'shop_id' => $this->main->id, 'amount' => 400, 'due_before' => 1000, 'due_after' => 600, 'method' => 'cash', 'client_uuid' => $uuid]);
        $this->assertTrue($this->logged('payment.collect', $this->main), 'collecting a due leaves an audit-trail row');

        // the very same request sent again (retry on a bad network) changes nothing
        $this->actingAs($this->mainOwner, 'web')->post(route('app.payments.store', $cust->id), ['amount' => 400, 'method' => 'cash', 'client_uuid' => $uuid])->assertSessionHasNoErrors();
        $this->assertEquals(600, (float) $cust->fresh()->due);
        $this->assertEquals(100000 + 400, $this->cash($this->main));
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());

        // a different method lands in the bank
        $this->actingAs($this->mainOwner, 'web')->post(route('app.payments.store', $cust->id), ['amount' => 100, 'method' => 'bkash'])->assertSessionHasNoErrors();
        $this->assertEquals(500, (float) $cust->fresh()->due);
        $this->assertEquals(50000 + 100, $this->bank($this->main));
        $this->assertEquals(100000 + 400, $this->cash($this->main));

        // more than the customer owes is refused
        $this->actingAs($this->mainOwner, 'web')->post(route('app.payments.store', $cust->id), ['amount' => 9999, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertEquals(500, (float) $cust->fresh()->due);
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_the_branch_collects_a_due_into_its_own_drawer_and_the_full_payment_clears_it(): void
    {
        $cust = $this->customer(800); // a business-wide customer: visible at both branches

        $this->actingAs($this->branchOwner, 'web')->post(route('app.payments.full', $cust->id), ['method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $cust->fresh()->due);
        $this->assertEquals(20000 + 800, $this->cash($this->branch), 'the branch drawer receives it');
        $this->assertEquals(100000, $this->cash($this->main), 'the main drawer does not');
        $this->assertDatabaseHas('payments', ['customer_id' => $cust->id, 'shop_id' => $this->branch->id, 'amount' => 800, 'due_before' => 800, 'due_after' => 0]);
        $this->assertTrue($this->logged('payment.collect', $this->branch));
    }

    public function test_applying_an_advance_reduces_due_and_advance_without_moving_any_cash(): void
    {
        $cust = $this->customer(1000, 300);

        $this->actingAs($this->mainOwner, 'web')->post(route('app.payments.applyAdvance', $cust->id))->assertSessionHasNoErrors();

        $fresh = $cust->fresh();
        $this->assertEquals(700, (float) $fresh->due);
        $this->assertEquals(0, (float) $fresh->advance_balance);
        $this->assertEquals(100000, $this->cash($this->main), 'a pure internal transfer: no cash moves');
        $this->assertEquals(50000, $this->bank($this->main));
    }

    // ================================================== 5. STAFF + ADMIN IMPERSONATION

    public function test_staff_permissions_are_kept_on_a_partial_edit_replaced_on_a_full_edit_and_enforced_on_real_routes(): void
    {
        // ---- create with permissions
        $this->actingAs($this->mainOwner, 'web')->post(route('app.staff.store'), [
            'name' => 'Cashier One', 'phone' => '01900009999', 'password' => 'secret1', 'permissions' => ['stock'],
        ])->assertSessionHasNoErrors();
        $cashier = User::where('phone', '01900009999')->firstOrFail();
        $this->assertSame('staff', $cashier->role);
        $this->assertSame($this->main->id, $cashier->shop_id);
        $this->assertSame(['stock'], $cashier->permissions);
        $this->assertTrue($this->logged('staff.create', $this->main), 'creating a cashier leaves an audit-trail row');

        // ---- no 'expenses' permission: the real route refuses
        $this->actingAs($cashier, 'web')->get(route('app.expenses'))->assertForbidden();
        $this->actingAs($cashier, 'web')->post(route('app.expenses.store'), ['amount' => 10, 'method' => 'cash'])->assertForbidden();
        $this->assertEquals(100000, $this->cash($this->main), 'the refused request moved no money');
        $this->actingAs($cashier, 'web')->get(route('app.staff.index'))->assertForbidden(); // owner-only whatever the grants

        // ---- partial edit (no permissions key) keeps them
        $this->actingAs($this->mainOwner, 'web')->put(route('app.staff.update', $cashier->id), ['name' => 'Cashier Renamed', 'phone' => '01900009999'])->assertSessionHasNoErrors();
        $this->assertSame('Cashier Renamed', $cashier->fresh()->name);
        $this->assertSame(['stock'], $cashier->fresh()->permissions);

        // ---- full edit with the key replaces them; the grant now works on the real route
        $this->actingAs($this->mainOwner, 'web')->put(route('app.staff.update', $cashier->id), ['name' => 'Cashier Renamed', 'phone' => '01900009999', 'permissions' => ['expenses']])->assertSessionHasNoErrors();
        $this->assertSame(['expenses'], $cashier->fresh()->permissions);
        $this->assertTrue($this->logged('staff.update', $this->main));
        $this->actingAs($cashier->fresh(), 'web')->get(route('app.expenses'))->assertOk();
        $this->actingAs($cashier->fresh(), 'web')->post(route('app.expenses.store'), ['amount' => 10, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(100000 - 10, $this->cash($this->main), 'now the cashier can record an expense');
        $this->actingAs($cashier->fresh(), 'web')->get(route('app.suppliers'))->assertForbidden(); // still no purchases perm

        // ---- an explicit empty list clears everything
        $this->actingAs($this->mainOwner, 'web')->put(route('app.staff.update', $cashier->id), ['name' => 'Cashier Renamed', 'phone' => '01900009999', 'permissions' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $cashier->fresh()->permissions);
        $this->actingAs($cashier->fresh(), 'web')->get(route('app.expenses'))->assertForbidden();

        // ---- another shop's owner cannot touch this cashier
        $this->actingAs($this->branchOwner, 'web')->put(route('app.staff.update', $cashier->id), ['name' => 'Hacked', 'phone' => '01900009999', 'permissions' => ['accounts']])->assertStatus(404);
        $this->assertSame('Cashier Renamed', $cashier->fresh()->name);
    }

    public function test_admin_impersonation_start_and_stop_never_disturbs_the_owners_own_devices(): void
    {
        $this->mainOwner->forceFill(['remember_token' => 'owners-phone-token'])->save();
        $admin = Admin::create(['name' => 'Super', 'email' => 'e2e-super@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$this->main->id}/impersonate")->assertRedirect();
        $this->assertTrue(Auth::guard('web')->check(), 'the admin is now viewing as the owner');
        $this->assertSame($this->mainOwner->id, Auth::guard('web')->id());
        $this->assertSame('owners-phone-token', $this->mainOwner->fresh()->remember_token, 'start does not touch the token');

        // the impersonated session can really act (record an expense as the owner)
        $this->post(route('app.expenses.store'), ['amount' => 5, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(100000 - 5, $this->cash($this->main));

        $this->post('/admin/impersonate/stop')->assertRedirect(route('admin.shops.index'));
        $this->assertFalse(Auth::guard('web')->check(), 'the impersonation session is over');
        $this->assertTrue(Auth::guard('admin')->check(), 'the admin stays logged in');
        $this->assertSame('owners-phone-token', $this->mainOwner->fresh()->remember_token, "the owner's remember-me login on every phone survives");
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'impersonate.start']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'impersonate.stop']);
    }

    // ================================================== 6. ACCOUNTS AFTER A CHAIN OF EVERYTHING ABOVE

    public function test_accounts_page_equals_an_independent_recomputation_after_a_chain_of_money_and_stock_flows(): void
    {
        $bulu = $this->supplier($this->main, 'Bulu', 2000);
        [$m, $c, $mv, $bv] = $this->article('L-10010', ['40' => 10, '41' => 10], 100, 120, $bulu);
        [$m2, $c2, $mv2, $bv2] = $this->article('L-10011', ['38' => 6], 50, null, $bulu);
        $cust = $this->customer(900);

        // a chain: purchase on credit, supplier return (cash), expense, due collection, damage, supplier payment
        $this->actingAs($this->mainOwner, 'web')->post(route('app.purchases.store'), ['supplier_id' => $bulu->id, 'amount' => 600, 'method' => 'credit', 'product_id' => $m->id, 'product_variant_id' => $mv['40']->id, 'qty' => 5, 'cost' => 120])->assertSessionHasNoErrors();
        $this->actingAs($this->mainOwner, 'web')->post(route('app.supplierReturns.store'), ['supplier_id' => $bulu->id, 'settlement_method' => 'cash', 'lines' => [['product_id' => $m->id, 'product_variant_id' => $mv['41']->id, 'qty' => 2, 'amount' => 240]]])->assertSessionHasNoErrors();
        $this->actingAs($this->mainOwner, 'web')->post(route('app.expenses.store'), ['amount' => 500, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($this->mainOwner, 'web')->post(route('app.payments.store', $cust->id), ['amount' => 300, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($this->mainOwner, 'web')->post(route('app.damages.store'), ['product_id' => $m2->id, 'product_variant_id' => $mv2['38']->id, 'qty' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->mainOwner, 'web')->post(route('app.suppliers.payments.store', $bulu->id), ['amount' => 400, 'method' => 'bkash'])->assertSessionHasNoErrors();

        // ---- independent expectations (computed here, not by the app)
        $expectCash = 100000 + 240 - 500 + 300;
        $expectBank = 50000 - 400;
        $expectPayable = 2000 + 600 - 400;
        $expectReceivable = 900 - 300;
        $expectStock = (15 * 120) + (8 * 120) + (5 * 50); // L-10010: 40->15, 41->8 @120 ; L-10011: 38->5 @ product cost 50
        $expectNet = $expectCash + $expectBank + $expectStock + $expectReceivable - $expectPayable;

        foreach ([[$this->mainOwner, $this->main], [$this->branchOwner, $this->branch]] as [$owner, $shop]) {
            $resp = $this->actingAs($owner, 'web')->get(route('app.accounts'));
            $resp->assertOk()->assertInertia(function ($p) use ($shop, $expectCash, $expectBank, $expectStock, $expectReceivable, $expectPayable, $expectNet) {
                $branchCash = $shop->parent_shop_id ? 20000 : $expectCash;
                $branchBank = $shop->parent_shop_id ? 8000 : $expectBank;
                $p->where('cash', fn ($v) => (float) $v === (float) $branchCash)
                    ->where('bank', fn ($v) => (float) $v === (float) $branchBank)
                    ->where('stockValue', fn ($v) => abs((float) $v - $expectStock) < 0.01)      // pooled: identical in BOTH branches
                    ->where('receivable', fn ($v) => (float) $v === (float) $expectReceivable);   // business-wide customer ledger
                if (! $shop->parent_shop_id) {
                    $p->where('payable', fn ($v) => (float) $v === (float) $expectPayable);       // the main shop's own supplier ledger
                    $p->where('netWorth', fn ($v) => abs((float) $v - $expectNet) < 0.01);
                }
            });
        }

        // the net worth formula itself, read straight off the page: cash + bank + stock + receivable + cod − payable
        $props = null;
        $this->actingAs($this->mainOwner, 'web')->get(route('app.accounts'))->assertInertia(function ($p) use (&$props) {
            $props = $p->toArray()['props'];
        });
        $this->assertEqualsWithDelta($props['cash'] + $props['bank'] + $props['stockValue'] + $props['receivable'] + $props['codReceivable'] - $props['payable'], $props['netWorth'], 0.01);

        // ---- both branches' pooled stock equal after the whole chain
        $this->assertPoolEqual('after the chain', ['40' => [$mv['40'], $bv['40']], '41' => [$mv['41'], $bv['41']]], [$m, $c]);
        $this->assertPoolEqual('after the chain (2)', ['38' => [$mv2['38'], $bv2['38']]], [$m2, $c2]);
        $this->assertEquals(15, $this->stockOf($bv['40']->id));
        $this->assertEquals(8, $this->stockOf($bv['41']->id));
        $this->assertEquals(5, $this->stockOf($bv2['38']->id));

        // ---- supplier report: rows add up to the stock value; total received = in stock + sold
        foreach ([$this->mainOwner, $this->branchOwner] as $owner) {
            $props = null;
            $this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier'))->assertOk()->assertInertia(function ($p) use (&$props) {
                $props = $p->toArray()['props'];
            });
            $rows = collect($props['rows']);
            $this->assertEqualsWithDelta($expectStock, $rows->sum('value'), 0.01, 'supplier rows add up to the real stock value');
            foreach ($rows as $r) {
                $this->assertEqualsWithDelta($r['qty'] + $r['soldQty'], $r['everQty'], 0.001, 'total received = in stock + sold');
                $this->assertEqualsWithDelta($r['sold'], collect($r['branches'])->sum('value'), 0.01, 'branch split adds up to sold');
            }
        }
    }
}
