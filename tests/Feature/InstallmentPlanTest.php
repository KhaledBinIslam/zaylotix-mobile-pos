<?php

namespace Tests\Feature;

use App\Console\Commands\ApplyInstallmentLateFees;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleItem;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A plan never touches customers.due itself — it only describes a schedule
 * over an already-existing due. Real collection still flows through the
 * existing PaymentController::store(), just tagged with the plan so
 * InstallmentAllocation can tell which installment(s) it covers.
 */
class InstallmentPlanTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creating_a_plan_builds_an_even_schedule_with_the_remainder_on_the_last_line(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 1000]);

        $response = $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id,
            'total_amount' => 1000,
            'down_payment' => 200,
            'installment_count' => 3,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ]);

        $plan = InstallmentPlan::first();
        $response->assertRedirect(route('app.installments.show', $plan->id));
        $this->assertSame('active', $plan->status);
        $this->assertEquals(200, (float) $plan->down_payment);

        $items = InstallmentScheduleItem::where('installment_plan_id', $plan->id)->orderBy('due_date')->get();
        $this->assertCount(3, $items);
        $this->assertEquals(333.33, (float) $items[0]->amount);
        $this->assertEquals(333.33, (float) $items[1]->amount);
        $this->assertEquals(333.34, (float) $items[2]->amount); // absorbs the rounding remainder
        $this->assertEquals(1000, (float) $items->sum('amount')); // schedule sums exactly to total_amount
        $this->assertSame(now()->toDateString(), $items[0]->due_date->toDateString());
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $items[1]->due_date->toDateString());
    }

    public function test_weekly_frequency_steps_by_seven_days(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);

        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 500, 'installment_count' => 2,
            'frequency' => 'weekly', 'start_date' => now()->toDateString(),
        ]);

        $items = InstallmentScheduleItem::orderBy('due_date')->get();
        $this->assertSame(now()->addWeek()->toDateString(), $items[1]->due_date->toDateString());
    }

    public function test_cannot_create_a_second_plan_for_the_same_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 1000]);
        $sale = Sale::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'user_id' => $owner->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 1000, 'discount' => 0, 'total' => 1000, 'profit' => 100, 'payment_mode' => 'credit']);
        $payload = ['sale_id' => $sale->id, 'customer_id' => $customer->id, 'total_amount' => 1000, 'installment_count' => 2, 'frequency' => 'monthly', 'start_date' => now()->toDateString()];
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), $payload)->assertRedirect();

        $response = $this->actingAs($owner, 'web')->postJson(route('app.installments.store'), $payload);

        $response->assertStatus(422);
        $this->assertSame(1, InstallmentPlan::count());
    }

    public function test_a_payment_tagged_to_a_plan_allocates_oldest_due_date_first_and_still_reduces_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 900]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 900, 'installment_count' => 3,
            'frequency' => 'monthly', 'start_date' => now()->toDateString(),
        ]);
        $plan = InstallmentPlan::first();

        // pays enough to fully cover installment 1 (300) and partially cover installment 2
        $response = $this->actingAs($owner, 'web')->post(route('app.payments.store', $customer->id), [
            'amount' => 400, 'method' => 'cash', 'installment_plan_id' => $plan->id,
        ]);

        $response->assertRedirect();
        $this->assertEquals(500, (float) $customer->fresh()->due); // 900 - 400, same generic due mechanism as any other payment

        $items = InstallmentScheduleItem::where('installment_plan_id', $plan->id)->orderBy('due_date')->get();
        $this->assertSame('paid', $items[0]->status);
        $this->assertEquals(300, (float) $items[0]->amount_paid);
        $this->assertSame('pending', $items[1]->status);
        $this->assertEquals(100, (float) $items[1]->amount_paid); // the 100 leftover spilled into installment 2
        $this->assertEquals(0, (float) $items[2]->amount_paid); // untouched

        $payment = Payment::first();
        $this->assertSame($plan->id, $payment->installment_plan_id);
    }

    public function test_plan_auto_completes_once_every_installment_is_fully_paid(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 500, 'installment_count' => 1,
            'frequency' => 'monthly', 'start_date' => now()->toDateString(),
        ]);
        $plan = InstallmentPlan::first();

        $this->actingAs($owner, 'web')->post(route('app.payments.store', $customer->id), [
            'amount' => 500, 'method' => 'cash', 'installment_plan_id' => $plan->id,
        ])->assertRedirect();

        $this->assertSame('completed', $plan->fresh()->status);
    }

    public function test_a_plan_shows_up_in_the_overdue_tab_once_its_due_date_passes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 500, 'installment_count' => 1,
            'frequency' => 'monthly', 'start_date' => now()->subDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($owner, 'web')->get(route('app.installments.index', ['tab' => 'overdue']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('plans.data', 1));
    }

    public function test_cancelling_a_plan_does_not_touch_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 500, 'installment_count' => 1,
            'frequency' => 'monthly', 'start_date' => now()->toDateString(),
        ]);
        $plan = InstallmentPlan::first();

        $response = $this->actingAs($owner, 'web')->post(route('app.installments.cancel', $plan->id));

        $response->assertRedirect();
        $this->assertSame('cancelled', $plan->fresh()->status);
        $this->assertEquals(500, (float) $customer->fresh()->due); // unchanged — the underlying due was never the plan's to touch
    }

    public function test_late_fee_command_charges_a_flat_fee_exactly_once_and_increases_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 500, 'installment_count' => 1,
            'frequency' => 'monthly', 'start_date' => now()->subDays(3)->toDateString(),
            'late_fee_type' => 'flat', 'late_fee_amount' => 50,
        ]);

        $this->artisan(ApplyInstallmentLateFees::class)->assertExitCode(0);

        $item = InstallmentScheduleItem::first();
        $this->assertEquals(50, (float) $item->late_fee_applied);
        $this->assertNotNull($item->late_fee_charged_at);
        $this->assertEquals(550, (float) $customer->fresh()->due); // 500 + the fee

        // running it again must never double-charge the same item
        $this->artisan(ApplyInstallmentLateFees::class)->assertExitCode(0);
        $this->assertEquals(50, (float) $item->fresh()->late_fee_applied);
        $this->assertEquals(550, (float) $customer->fresh()->due);
    }

    public function test_late_fee_command_supports_a_percent_fee(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 1000]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 1000, 'installment_count' => 1,
            'frequency' => 'monthly', 'start_date' => now()->subDays(1)->toDateString(),
            'late_fee_type' => 'percent', 'late_fee_amount' => 5,
        ]);

        $this->artisan(ApplyInstallmentLateFees::class);

        $item = InstallmentScheduleItem::first();
        $this->assertEquals(50, (float) $item->late_fee_applied); // 5% of 1000
        $this->assertEquals(1050, (float) $customer->fresh()->due);
    }

    public function test_late_fee_command_never_charges_an_item_that_is_not_yet_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);
        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id, 'total_amount' => 500, 'installment_count' => 1,
            'frequency' => 'monthly', 'start_date' => now()->addDays(5)->toDateString(),
            'late_fee_type' => 'flat', 'late_fee_amount' => 50,
        ]);

        $this->artisan(ApplyInstallmentLateFees::class);

        $this->assertEquals(0, (float) InstallmentScheduleItem::first()->late_fee_applied);
        $this->assertEquals(500, (float) $customer->fresh()->due);
    }

    public function test_plans_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'installment_sales');
        [$shopB] = $this->createShopWithOwner();
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Other', 'phone' => '01799999999', 'due' => 500]);
        $foreignPlan = InstallmentPlan::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'total_amount' => 500, 'installment_count' => 1, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active']);

        $response = $this->actingAs($ownerA, 'web')->get(route('app.installments.show', $foreignPlan->id));

        $response->assertStatus(404);
    }
}
