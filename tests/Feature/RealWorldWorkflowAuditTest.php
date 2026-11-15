<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
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
}
