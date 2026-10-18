<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A customer's due collections used to only ever move a single running
 * `customers.due` number - no record anywhere of what the due actually was
 * before/after a specific payment. Khaled's explicit request: a real
 * payment ledger per customer (date, due before, amount paid, due after).
 */
class CustomerPaymentHistoryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_collecting_a_partial_payment_snapshots_due_before_and_after(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 1500]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 750])
            ->assertSessionDoesntHaveErrors();

        $this->assertEquals(750, (float) $customer->fresh()->due);

        $payment = $customer->payments()->latest('id')->first();
        $this->assertSame(750.0, (float) $payment->amount);
        $this->assertSame(1500.0, (float) $payment->due_before);
        $this->assertSame(750.0, (float) $payment->due_after);
    }

    public function test_marking_fully_paid_snapshots_due_before_and_zero_after(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 400]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments/full")
            ->assertSessionDoesntHaveErrors();

        $payment = $customer->payments()->latest('id')->first();
        $this->assertSame(400.0, (float) $payment->due_before);
        $this->assertSame(0.0, (float) $payment->due_after);
    }

    public function test_history_endpoint_returns_the_ledger_newest_first_with_the_collecting_user(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 1500]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 500]);
        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 300]);

        $response = $this->actingAs($owner, 'web')->getJson("/app/customers/{$customer->id}/payments");

        $response->assertOk();
        $payments = $response->json('payments');
        $this->assertCount(2, $payments);
        // newest first
        $this->assertEquals(300, (float) $payments[0]['amount']);
        $this->assertEquals(500, (float) $payments[1]['amount']);
        $this->assertEquals(1000, (float) $payments[0]['due_before']);
        $this->assertEquals(700, (float) $payments[0]['due_after']);
        $this->assertSame($owner->name, $payments[0]['user']['name']);
    }

    public function test_history_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Not Yours', 'due' => 1000]);
        $this->actingAs($ownerB, 'web')->post("/app/customers/{$customerB->id}/payments", ['amount' => 400]);

        // TenantScope already keeps the route-model-binding itself from
        // ever finding another shop's customer - a plain 404 (never
        // revealing the row exists at all), not a 403, same as every
        // other tenant-isolation test in this suite.
        $this->actingAs($ownerA, 'web')->getJson("/app/customers/{$customerB->id}/payments")
            ->assertNotFound();
    }

    // ---- Phase 3.2: optional per-invoice tagging (see docs/FIX_PLAN.md) ----

    public function test_a_payment_can_be_tagged_to_a_specific_invoice(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);
        $sale = Sale::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 500, 'total' => 500, 'profit' => 100, 'payment_mode' => 'credit']);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 200, 'sale_id' => $sale->id])
            ->assertSessionDoesntHaveErrors();

        $this->assertEquals(300, (float) $customer->fresh()->due);
        $payment = $customer->payments()->latest('id')->first();
        $this->assertSame($sale->id, $payment->sale_id);
    }

    public function test_a_payment_without_an_invoice_still_works_exactly_as_before(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 200])
            ->assertSessionDoesntHaveErrors();

        $this->assertEquals(300, (float) $customer->fresh()->due);
        $this->assertNull($customer->payments()->latest('id')->first()->sale_id);
    }

    public function test_cannot_tag_a_payment_to_another_customers_invoice(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);
        $otherCustomer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);
        $otherSale = Sale::create(['shop_id' => $shop->id, 'customer_id' => $otherCustomer->id, 'invoice_no' => 'INV-2', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 100, 'total' => 100, 'profit' => 20, 'payment_mode' => 'cash']);

        $response = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 100, 'sale_id' => $otherSale->id]);

        $response->assertSessionHasErrors('sale_id');
        $this->assertEquals(500, (float) $customer->fresh()->due); // unchanged
    }

    public function test_invoices_endpoint_shows_due_at_checkout_and_only_tagged_payments(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 800]);
        $saleA = Sale::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-A', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 500, 'total' => 500, 'profit' => 100, 'payment_mode' => 'credit']);
        $saleB = Sale::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-B', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 300, 'total' => 300, 'profit' => 60, 'payment_mode' => 'credit']);
        // a general (untagged) payment — must NOT show up as "paid toward" either invoice
        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 100]);
        // a payment explicitly tagged to invoice A
        $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 150, 'sale_id' => $saleA->id]);

        $response = $this->actingAs($owner, 'web')->getJson("/app/customers/{$customer->id}/invoices");

        $response->assertOk();
        $invoices = collect($response->json('invoices'))->keyBy('invoice_no');
        $this->assertEquals(500.0, $invoices['INV-A']['due_at_checkout']);
        $this->assertEquals(150.0, $invoices['INV-A']['tagged_paid']); // only the explicitly-tagged payment
        $this->assertEquals(300.0, $invoices['INV-B']['due_at_checkout']);
        $this->assertEquals(0.0, $invoices['INV-B']['tagged_paid']); // the untagged ৳100 payment doesn't count here
    }

    public function test_invoices_endpoint_excludes_a_voided_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 0]);
        Sale::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-V', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 200, 'total' => 200, 'profit' => 40, 'payment_mode' => 'credit', 'voided_at' => now()]);

        $response = $this->actingAs($owner, 'web')->getJson("/app/customers/{$customer->id}/invoices");

        $response->assertOk()->assertJson(['invoices' => []]);
    }

    public function test_invoices_endpoint_excludes_a_sale_already_fully_paid_at_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 0]);
        $sale = Sale::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-PAID', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 200, 'total' => 200, 'profit' => 40, 'payment_mode' => 'cash']);
        SalePayment::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 200]);

        $response = $this->actingAs($owner, 'web')->getJson("/app/customers/{$customer->id}/invoices");

        $response->assertOk()->assertJson(['invoices' => []]);
    }

    public function test_staff_without_due_permission_cannot_view_history(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900003333',
            'password' => 'secret1234', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn',
        ]);

        $this->actingAs($cashier, 'web')->getJson("/app/customers/{$customer->id}/payments")
            ->assertForbidden();
    }
}
