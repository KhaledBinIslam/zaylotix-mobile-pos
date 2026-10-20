<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "অ্যাডভান্স বুকিং/লে-অ্যাওয়ে" — see docs/FIX_PLAN.md's design note.
 * Mechanically the same InstallmentPlan the existing installment-sales
 * feature already creates (reused as-is, see InstallmentPlanTest for its
 * own schedule-building coverage) — this file only covers the two things
 * genuinely new: the `booking` tag and `picked_up_at` pickup tracking.
 */
class AdvanceBookingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithFeature(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'installment_sales');

        return [$shop, $owner];
    }

    public function test_creating_a_booking_plan_tags_it_and_defaults_picked_up_at_to_null(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);

        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id,
            'booking' => true,
            'total_amount' => 500,
            'installment_count' => 1,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertRedirect();

        $plan = InstallmentPlan::first();
        $this->assertTrue($plan->booking);
        $this->assertNull($plan->picked_up_at);
    }

    public function test_a_plain_installment_plan_defaults_booking_to_false(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);

        $this->actingAs($owner, 'web')->post(route('app.installments.store'), [
            'customer_id' => $customer->id,
            'total_amount' => 500,
            'installment_count' => 2,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertFalse(InstallmentPlan::first()->booking);
    }

    public function test_pickup_stamps_picked_up_at(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111']);
        $plan = InstallmentPlan::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'booking' => true, 'total_amount' => 500, 'installment_count' => 1, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active']);

        $this->actingAs($owner, 'web')->post(route('app.installments.pickup', $plan->id))->assertRedirect();

        $this->assertNotNull($plan->fresh()->picked_up_at);
    }

    public function test_undo_pickup_clears_picked_up_at(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111']);
        $plan = InstallmentPlan::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'booking' => true, 'total_amount' => 500, 'installment_count' => 1, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active', 'picked_up_at' => now()]);

        $this->actingAs($owner, 'web')->post(route('app.installments.undoPickup', $plan->id))->assertRedirect();

        $this->assertNull($plan->fresh()->picked_up_at);
    }

    public function test_pickup_is_rejected_for_a_plan_that_is_not_a_booking(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111']);
        $plan = InstallmentPlan::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'booking' => false, 'total_amount' => 500, 'installment_count' => 2, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active']);

        $this->actingAs($owner, 'web')->post(route('app.installments.pickup', $plan->id))->assertStatus(422);
    }

    public function test_booking_tab_lists_only_active_unpicked_up_bookings(): void
    {
        [$shop, $owner] = $this->shopWithFeature();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111']);
        $pendingBooking = InstallmentPlan::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'booking' => true, 'total_amount' => 500, 'installment_count' => 1, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active']);
        InstallmentPlan::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'booking' => true, 'total_amount' => 300, 'installment_count' => 1, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active', 'picked_up_at' => now()]);
        InstallmentPlan::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'booking' => false, 'total_amount' => 400, 'installment_count' => 2, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active']);

        $this->actingAs($owner, 'web')->get(route('app.installments.index', ['tab' => 'booking']))->assertInertia(fn ($page) => $page
            ->has('plans.data', 1)
            ->where('plans.data.0.id', $pendingBooking->id)
        );
    }

    public function test_cannot_pickup_another_shops_booking(): void
    {
        [$shopA, $ownerA] = $this->shopWithFeature();
        [$shopB] = $this->shopWithFeature();
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Rahim', 'phone' => '01722222222']);
        $otherPlan = InstallmentPlan::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'booking' => true, 'total_amount' => 500, 'installment_count' => 1, 'frequency' => 'monthly', 'start_date' => now()->toDateString(), 'status' => 'active']);

        $this->actingAs($ownerA, 'web')->post(route('app.installments.pickup', $otherPlan->id))->assertNotFound();
    }
}
