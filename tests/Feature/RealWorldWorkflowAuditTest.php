<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled's exact end-to-end business scenario (2026-11-15 audit request),
 * run through the REAL routes/middleware/controllers/validation — nothing
 * mocked, nothing asserted from reading the code alone. This is the same
 * method (actingAs + postJson against real routes) every other Feature
 * test in this suite already uses, which is how every real bug fixed this
 * session (ZV barcode, checkout confirm dialog, exchange discount) was
 * actually caught — not a new/weaker kind of test.
 *
 * Scenario: product starts at 100 stock, sell 5 units at ৳400 each
 * (৳2000 invoice), customer pays ৳1000 up front (due ৳1000), later pays
 * ৳400 more (due ৳600) — every number must match across Sales History,
 * the Due Ledger (customer.due), and the payment ledger (customers.payments).
 */
class RealWorldWorkflowAuditTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_full_sale_to_partial_due_collection_workflow_stays_consistent_everywhere(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 100]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'phone' => '01711112222', 'due' => 0]);

        // Step 1: sell 5 units = ৳2000, customer pays ৳1000 cash — ৳1000 goes to due
        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'customer_id' => $customer->id,
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ]);
        $checkout->assertOk();
        $invoiceNo = $checkout->json('sale.invoice_no');

        // stock: 100 - 5 = 95
        $this->assertEquals(95, $product->fresh()->stock, 'stock must deduct exactly the qty sold');
        // invoice total ৳2000, due = 2000 - 1000 = ৳1000
        $this->assertEquals(2000.0, (float) $checkout->json('sale.total'));
        $this->assertEquals(1000.0, (float) $customer->fresh()->due, 'due must be exactly total minus tendered');
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance, 'cash drawer must reflect only what was actually tendered');

        // Step 2: Sales History must show this exact invoice with the same total
        $history = $this->actingAs($owner, 'web')->get('/app/sales');
        $history->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales
            ->where('0.invoice_no', $invoiceNo)
            ->where('0.total', fn ($total) => (float) $total === 2000.0)
            ->etc()));

        // Step 3: customer pays ৳400 more against the due
        $payment = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 400]);
        $payment->assertSessionDoesntHaveErrors();

        // remaining due must now be exactly ৳600, everywhere
        $this->assertEquals(600.0, (float) $customer->fresh()->due, 'remaining due must be 1000 - 400 = 600');
        $ledgerRow = $customer->payments()->latest('id')->first();
        $this->assertEquals(1000.0, (float) $ledgerRow->due_before);
        $this->assertEquals(600.0, (float) $ledgerRow->due_after);
        $this->assertEquals(400.0, (float) $ledgerRow->amount);

        // Due Ledger page (CustomerController::index, same list app.customers renders) must show the same ৳600
        $dueLedger = $this->actingAs($owner, 'web')->get('/app/customers');
        $dueLedger->assertInertia(fn ($page) => $page->has('customers', fn ($customers) => $customers
            ->where('0.due', fn ($due) => (float) $due === 600.0)
            ->etc()));

        // stock must still read 95 — a due payment must never touch inventory
        $this->assertEquals(95, $product->fresh()->stock);
    }

    /**
     * Double-tap safety: the exact same checkout request sent twice (same
     * client_uuid, as a slow network/double-tap would replay it) must never
     * double-sell or double-deduct stock. Already covered generically by
     * CheckoutTransactionTest; repeated here with Khaled's own numbers as
     * direct evidence for this audit.
     */
    public function test_a_resubmitted_checkout_never_double_sells_or_double_deducts_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 100]);
        $uuid = (string) Str::uuid();
        $payload = [
            'client_uuid' => $uuid,
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 2000]],
        ];

        $first = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload);
        $second = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(1, Sale::where('shop_id', $shop->id)->count(), 'a resubmit must never create a second sale row');
        $this->assertEquals(95, $product->fresh()->stock, 'stock must be deducted exactly once, not twice');
        $this->assertSame($first->json('sale.id'), $second->json('sale.id'), 'the resubmit must return the original sale, not a new one');
    }

    /**
     * Voiding (cancelling) a sale must reverse stock, cash and due back to
     * their pre-sale state exactly — not approximately.
     */
    public function test_voiding_the_sale_reverses_stock_cash_and_due_exactly(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 100]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'phone' => '01711112222', 'due' => 0]);

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'customer_id' => $customer->id,
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ]);
        $saleId = $checkout->json('sale.id');
        $this->assertEquals(95, $product->fresh()->stock);
        $this->assertEquals(1000.0, (float) $customer->fresh()->due);

        $void = $this->actingAs($owner, 'web')->delete("/app/sales/{$saleId}", ['reason' => 'audit test — customer cancelled the whole order']);
        $void->assertRedirect();

        $this->assertEquals(100, $product->fresh()->stock, 'voiding must restore every unit sold');
        $this->assertEquals(0.0, (float) $customer->fresh()->due, 'voiding must remove the due this sale created');
        $this->assertEquals(0.0, (float) $shop->fresh()->cash_balance, 'voiding must give back the cash this sale brought in');
        $this->assertNotNull(Sale::withoutGlobalScopes()->find($saleId)->voided_at);
    }

    /**
     * Returning part of the sale (not a full void) must settle refund vs
     * due proportionally and leave the invoice itself still visible in
     * Sales History with its original figures — a return is a separate
     * ledger event, not a rewrite of history.
     */
    public function test_partial_return_after_the_sale_settles_due_and_keeps_the_original_invoice_intact(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 100]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'phone' => '01711112222', 'due' => 0]);

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'customer_id' => $customer->id,
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ]);
        $saleId = $checkout->json('sale.id');
        $saleItemId = $checkout->json('sale.items.0.id');

        // 1 of 5 units (worth ৳400) comes back, resalable. The original sale
        // was 50% cash / 50% due (1000 tendered of a 2000 total), so this
        // ৳400 refund must split the same way: ৳200 reduces due, ৳200 pays
        // out of the drawer — neither side absorbs the whole thing.
        $return = $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $saleId,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItemId, 'qty' => 1, 'condition' => 'resalable']],
        ]);
        $return->assertOk();

        $this->assertEquals(96, $product->fresh()->stock, '95 after the sale + 1 returned');
        $this->assertEquals(800.0, (float) $customer->fresh()->due, '1000 due - 200 (the due-side half of the ৳400 refund)');
        $this->assertEquals(800.0, (float) $shop->fresh()->cash_balance, '1000 tendered - 200 (the cash-side half of the ৳400 refund)');

        // the original invoice is still in Sales History with its original ৳2000 total, unmodified
        $this->assertEquals(2000.0, (float) Sale::find($saleId)->total);
        $this->assertSame(1, SalesReturn::where('sale_id', $saleId)->count());
    }

    /**
     * FIXED (2026-11-15 audit): /app/customers/{id}/payments now takes the
     * same client_uuid idempotency key /app/pos/checkout already had.
     * Before the fix, a cashier double-tapping "কালেক্ট" on a slow
     * connection created two separate Payment rows and double-collected the
     * same money — a real bug, not a hypothetical one (see git history for
     * the before-fix version of this test, which asserted exactly that).
     */
    public function test_a_resubmitted_due_payment_with_the_same_client_uuid_is_not_double_collected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 1000]);
        $uuid = (string) Str::uuid();

        // simulates a cashier double-tapping "কালেক্ট" — the exact same
        // request, sent twice, same idempotency key both times
        $first = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 400, 'client_uuid' => $uuid]);
        $second = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 400, 'client_uuid' => $uuid]);

        $first->assertSessionDoesntHaveErrors();
        $second->assertSessionDoesntHaveErrors();

        $this->assertSame(1, $customer->payments()->where('amount', 400)->count(), 'the replay must not create a second ledger row');
        $this->assertEquals(600.0, (float) $customer->fresh()->due, 'due must be reduced exactly once (1000 - 400), not twice');
    }

    /** Two genuinely different due collections (no shared uuid) must both still go through normally — the idempotency fix must never block real repeat payments. */
    public function test_two_genuinely_different_due_payments_both_still_collect(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 1000]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 400, 'client_uuid' => (string) Str::uuid()])
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 300, 'client_uuid' => (string) Str::uuid()])
            ->assertSessionDoesntHaveErrors();

        $this->assertEquals(2, $customer->payments()->count());
        $this->assertEquals(300.0, (float) $customer->fresh()->due, '1000 - 400 - 300');
    }

    /** Split payment at checkout (cash + bkash together) must add up correctly and never silently drop a method. */
    public function test_split_payment_checkout_records_every_method_and_sums_to_the_total(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0, 'bank_balance' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 10]);

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [
                ['method' => 'cash', 'amount' => 1200],
                ['method' => 'bkash', 'amount' => 800],
            ],
        ]);
        $checkout->assertOk();

        $this->assertEquals(2000.0, (float) $checkout->json('sale.total'));
        $this->assertSame('split', $checkout->json('sale.payment_mode'));
        $this->assertEquals(1200.0, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(800.0, (float) $shop->fresh()->bank_balance);
        $this->assertEquals(2, $checkout->json('sale.id') ? \App\Models\SalePayment::where('sale_id', $checkout->json('sale.id'))->count() : 0);
    }

    /** Sales History's own invoice search (`q`) — the only search it currently offers (see finding below). */
    public function test_sales_history_invoice_search_finds_by_partial_invoice_number(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 10]);
        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ]);
        $invoiceNo = $checkout->json('sale.invoice_no');

        $response = $this->actingAs($owner, 'web')->get('/app/sales?q='.substr($invoiceNo, -3));

        $response->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->where('0.invoice_no', $invoiceNo)->etc()));
    }

    /**
     * FIXED (2026-11-16 audit): Sales History (/app/sales,
     * SalesController::index) now supports a date-range filter and a
     * customer-name/phone search (alongside the existing invoice
     * substring search) — the exact, rule-based replacement Khaled asked
     * for after the hidden-invoice-exclusion request was declined. A
     * date range outside the sale's own date must exclude it; the same
     * real sale within range must still show up.
     */
    public function test_sales_history_date_range_filter_actually_filters(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 10]);
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ]);

        // a range that cannot include today's sale must exclude it
        $this->actingAs($owner, 'web')->get('/app/sales?from=2020-01-01&to=2020-01-02')
            ->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->count(0)->etc()));

        // the same real sale, searched with no date restriction, still shows up
        $this->actingAs($owner, 'web')->get('/app/sales')
            ->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->count(1)->etc()));
    }

    /** Customer-name/phone search on Sales History — the other half of the same fix. */
    public function test_sales_history_customer_search_finds_by_name_or_phone(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 10]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim Mia', 'phone' => '01799998877', 'due' => 0]);
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_id' => $customer->id,
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ]);

        $this->actingAs($owner, 'web')->get('/app/sales?q=Karim')
            ->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->count(1)->etc()));
        $this->actingAs($owner, 'web')->get('/app/sales?q=01799998877')
            ->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->count(1)->etc()));
        $this->actingAs($owner, 'web')->get('/app/sales?q=NoSuchCustomer')
            ->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->count(0)->etc()));
    }

    /** Category-wise filter — must only show sales containing at least one item from that category, never change stock/payments/due. */
    public function test_sales_history_category_filter_shows_only_matching_sales(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $catA = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Category A']);
        $catB = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Category B']);
        $productA = Product::create(['shop_id' => $shop->id, 'category_id' => $catA->id, 'name' => 'A Product', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $productB = Product::create(['shop_id' => $shop->id, 'category_id' => $catB->id, 'name' => 'B Product', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $productA->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $productB->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $this->actingAs($owner, 'web')->get('/app/sales?category_id='.$catA->id)
            ->assertInertia(fn ($page) => $page->has('sales.data', fn ($sales) => $sales->count(1)->etc()));

        $this->assertEquals(9, $productA->fresh()->stock);
        $this->assertEquals(9, $productB->fresh()->stock);
    }

    /** Reports' own filtered summary (count/sales/paid/due) — same rule-based filters, never a hand-picked invoice subset. */
    public function test_reports_filtered_invoice_stats_match_date_and_category_filters(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cat = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Filtered Category']);
        $product = Product::create(['shop_id' => $shop->id, 'category_id' => $cat->id, 'name' => 'Filtered Product', 'cost' => 50, 'price' => 1000, 'stock' => 10]);
        $otherCat = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Other Category']);
        $otherProduct = Product::create(['shop_id' => $shop->id, 'category_id' => $otherCat->id, 'name' => 'Other Product', 'cost' => 50, 'price' => 500, 'stock' => 10]);

        // one fully paid sale in the target category, partially paid (due)
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_id' => Customer::create(['shop_id' => $shop->id, 'name' => 'Due Customer', 'due' => 0])->id,
            'payments' => [['method' => 'cash', 'amount' => 600]],
        ])->assertOk();
        // a sale in a DIFFERENT category, must not be counted
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $otherProduct->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 500]],
        ])->assertOk();

        $today = now()->toDateString();
        $stats = \App\Support\Reports::filteredInvoiceStats($today, $today, $cat->id, null);

        $this->assertEquals(1, $stats['count']);
        $this->assertEquals(1000.0, $stats['sales_amt']);
        $this->assertEquals(600.0, $stats['paid']);
        $this->assertEquals(400.0, $stats['due']);
    }

    /**
     * Soft-delete/restore consistency: deleting the product a sale already
     * references must not corrupt that sale's own historical figures —
     * the sale row snapshots its own product_name/price/cost at sale time,
     * it never re-reads the live product.
     */
    public function test_deleting_the_product_after_the_sale_does_not_corrupt_that_sales_own_figures(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 10]);
        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ]);
        $saleId = $checkout->json('sale.id');

        $product->delete();

        $response = $this->actingAs($owner, 'web')->get("/app/sales/{$saleId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('sale.total', fn ($total) => (float) $total === 400.0)
            ->where('sale.items.0.product_name', 'Test Product'));
    }

    /** Two cashiers checking out from the same shop concurrently must never corrupt the shared stock/cash counters — each sale locks the shop row, so updates serialize instead of racing. */
    public function test_two_cashiers_selling_at_nearly_the_same_time_never_lose_a_stock_update(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900001111', 'password' => 'secret1', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 250, 'price' => 400, 'stock' => 10]);

        // not truly simultaneous threads (PHPUnit is single-threaded), but
        // this is exactly the code path two real concurrent requests take —
        // each performCheckout() call locks the shop row for the duration
        // of its own transaction, which is what actually prevents the race
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 1200]],
        ])->assertOk();
        $this->actingAs($cashier, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 1600]],
        ])->assertOk();

        $this->assertEquals(3, $product->fresh()->stock, '10 - 3 - 4 = 3, neither sale\'s deduction was lost');
        $this->assertEquals(2800.0, (float) $shop->fresh()->cash_balance, '1200 + 1600, neither cashier\'s cash was lost');
        $this->assertSame(2, Sale::where('shop_id', $shop->id)->count());
    }
}
