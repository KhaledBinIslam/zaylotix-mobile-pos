<?php

namespace Tests\Feature;

use App\Models\Customer;
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
