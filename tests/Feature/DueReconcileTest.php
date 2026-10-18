<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 3 (definition-of-done checklist) — see docs/FIX_PLAN.md.
 */
class DueReconcileTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_passes_clean_when_nothing_is_wrong(): void
    {
        [$shop] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 100]);
        Payment::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'amount' => 50, 'due_before' => 150, 'due_after' => 100, 'method' => 'cash', 'date' => now()->toDateString()]);

        $this->artisan('due:reconcile')->assertExitCode(0);
    }

    public function test_flags_a_negative_due(): void
    {
        [$shop] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => -50]);

        $this->artisan('due:reconcile')->assertExitCode(1);
    }

    public function test_flags_a_broken_payment_ledger_chain(): void
    {
        [$shop] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 50]);
        Payment::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'amount' => 50, 'due_before' => 150, 'due_after' => 100, 'method' => 'cash', 'date' => now()->toDateString()]);
        // a second payment whose due_before doesn't match the first one's due_after
        Payment::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'amount' => 20, 'due_before' => 130, 'due_after' => 110, 'method' => 'cash', 'date' => now()->toDateString()]);

        $this->artisan('due:reconcile')->assertExitCode(1);
    }

    public function test_scoped_to_one_shop_when_given(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shopA->id, 'name' => 'Clean', 'due' => 50]);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Broken', 'due' => -10]);

        $this->artisan('due:reconcile', ['--shop' => $shopA->id])->assertExitCode(0);
        $this->artisan('due:reconcile', ['--shop' => $shopB->id])->assertExitCode(1);
    }
}
