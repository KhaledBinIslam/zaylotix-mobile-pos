<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Ambassador;
use App\Models\AmbassadorCommission;
use App\Models\SubscriptionPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AmbassadorTrackingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'amb-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    private function supportAdmin(): Admin
    {
        return Admin::create(['name' => 'Support', 'email' => 'amb-sup-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'support']);
    }

    /** Khaled's explicit simplification: the "+ New ambassador" quick-add only ever asks for name + employee ID + which shop — nothing money-related. */
    public function test_creating_an_ambassador_only_needs_name_employee_id_and_shop(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->actingAs($this->superAdmin(), 'admin')->post('/admin/ambassadors', [
            'name' => 'Karim', 'employee_id' => 'EMP-007', 'shop_id' => $shop->id,
        ])->assertRedirect();

        $ambassador = Ambassador::where('employee_id', 'EMP-007')->first();
        $this->assertNotNull($ambassador);
        $this->assertSame('Karim', $ambassador->name);
        $this->assertSame(0.0, (float) $ambassador->one_time_bonus); // never asked for, defaults to 0
        $this->assertSame(0.0, (float) $ambassador->recurring_percent);
        $this->assertSame($ambassador->id, $shop->fresh()->currentAmbassador()?->id); // the shop pick also assigns them
    }

    public function test_a_support_admin_cannot_reach_any_ambassador_route(): void
    {
        [$shop] = $this->createShopWithOwner();
        $ambassador = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 500, 'recurring_percent' => 10]);
        $support = $this->supportAdmin();

        $this->actingAs($support, 'admin')->get('/admin/ambassadors')->assertStatus(403);
        $this->actingAs($support, 'admin')->get("/admin/ambassadors/{$ambassador->id}")->assertStatus(403);
        $this->actingAs($support, 'admin')->post('/admin/ambassadors', ['name' => 'x', 'one_time_bonus' => 0, 'recurring_percent' => 0])->assertStatus(403);
        $this->actingAs($support, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['current_ambassador_id' => $ambassador->id])->assertStatus(403);
    }

    public function test_a_support_admin_never_receives_ambassador_data_on_the_shop_edit_page(): void
    {
        [$shop] = $this->createShopWithOwner(['business_type_id' => \App\Models\BusinessType::create(['slug' => 'g', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true])->id]);
        Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 500, 'recurring_percent' => 10]);

        $this->actingAs($this->supportAdmin(), 'admin')->get("/admin/shops/{$shop->id}/edit")->assertInertia(fn ($page) => $page
            ->where('ambassadors', null)
        );
    }

    public function test_marking_a_shop_as_brought_by_an_ambassador_creates_a_one_time_commission(): void
    {
        [$shop] = $this->createShopWithOwner();
        $ambassador = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 500, 'recurring_percent' => 10]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->post("/admin/shops/{$shop->id}/ambassador", ['brought_by_ambassador_id' => $ambassador->id])
            ->assertRedirect();

        $this->assertSame($ambassador->id, $shop->fresh()->brought_by_ambassador_id);
        $this->assertDatabaseHas('ambassador_commissions', [
            'ambassador_id' => $ambassador->id, 'shop_id' => $shop->id, 'type' => 'one_time', 'amount' => 500, 'status' => 'unpaid',
        ]);
    }

    public function test_correcting_brought_by_never_creates_a_second_one_time_commission(): void
    {
        [$shop] = $this->createShopWithOwner();
        $karim = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 500, 'recurring_percent' => 0]);
        $rahim = Ambassador::create(['name' => 'Rahim', 'one_time_bonus' => 300, 'recurring_percent' => 0]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['brought_by_ambassador_id' => $karim->id])->assertRedirect();
        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['brought_by_ambassador_id' => $rahim->id])->assertRedirect();

        $this->assertSame($rahim->id, $shop->fresh()->brought_by_ambassador_id); // correction applied
        $this->assertSame(1, AmbassadorCommission::where('shop_id', $shop->id)->where('type', 'one_time')->count()); // only the original bonus
        $this->assertDatabaseHas('ambassador_commissions', ['ambassador_id' => $karim->id, 'amount' => 500]);
    }

    public function test_assigning_a_current_ambassador_closes_the_previous_assignment(): void
    {
        [$shop] = $this->createShopWithOwner();
        $karim = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 0, 'recurring_percent' => 10]);
        $rahim = Ambassador::create(['name' => 'Rahim', 'one_time_bonus' => 0, 'recurring_percent' => 15]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['current_ambassador_id' => $karim->id])->assertRedirect();
        $this->assertSame($karim->id, $shop->fresh()->currentAmbassador()?->id);

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['current_ambassador_id' => $rahim->id])->assertRedirect();
        $this->assertSame($rahim->id, $shop->fresh()->currentAmbassador()?->id);

        $this->assertSame(1, \App\Models\AmbassadorShopAssignment::where('shop_id', $shop->id)->where('ambassador_id', $karim->id)->whereNotNull('unassigned_at')->count());
    }

    public function test_a_subscription_payment_generates_a_recurring_commission_for_the_current_ambassador(): void
    {
        [$shop] = $this->createShopWithOwner();
        $ambassador = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 0, 'recurring_percent' => 10]);
        $this->actingAs($this->superAdmin(), 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['current_ambassador_id' => $ambassador->id]);

        $payment = SubscriptionPayment::create([
            'shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 1000, 'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);

        $this->assertDatabaseHas('ambassador_commissions', [
            'ambassador_id' => $ambassador->id, 'shop_id' => $shop->id, 'type' => 'recurring',
            'subscription_payment_id' => $payment->id, 'amount' => 100, 'status' => 'unpaid',
        ]);
    }

    public function test_a_subscription_payment_on_an_unassigned_shop_creates_no_commission(): void
    {
        [$shop] = $this->createShopWithOwner();

        SubscriptionPayment::create([
            'shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 1000, 'month' => now()->format('Y-m'), 'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);

        $this->assertSame(0, AmbassadorCommission::count());
    }

    public function test_reassigning_the_ambassador_routes_future_recurring_commissions_to_the_new_one(): void
    {
        [$shop] = $this->createShopWithOwner();
        $karim = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 0, 'recurring_percent' => 10]);
        $rahim = Ambassador::create(['name' => 'Rahim', 'one_time_bonus' => 0, 'recurring_percent' => 20]);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['current_ambassador_id' => $karim->id]);
        SubscriptionPayment::create(['shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 1000, 'month' => '2026-01', 'method' => 'cash', 'paid_on' => '2026-01-05']);

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['current_ambassador_id' => $rahim->id]);
        SubscriptionPayment::create(['shop_id' => $shop->id, 'plan' => 'monthly', 'amount' => 1000, 'month' => '2026-02', 'method' => 'cash', 'paid_on' => '2026-02-05']);

        $this->assertDatabaseHas('ambassador_commissions', ['ambassador_id' => $karim->id, 'month' => '2026-01', 'amount' => 100]);
        $this->assertDatabaseHas('ambassador_commissions', ['ambassador_id' => $rahim->id, 'month' => '2026-02', 'amount' => 200]);
    }

    public function test_marking_a_month_paid_only_touches_that_ambassadors_unpaid_rows_for_that_month(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $ambassador = Ambassador::create(['name' => 'Karim', 'one_time_bonus' => 0, 'recurring_percent' => 10]);
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shopA->id}/ambassador", ['current_ambassador_id' => $ambassador->id]);
        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shopB->id}/ambassador", ['current_ambassador_id' => $ambassador->id]);

        SubscriptionPayment::create(['shop_id' => $shopA->id, 'plan' => 'monthly', 'amount' => 1000, 'month' => '2026-03', 'method' => 'cash', 'paid_on' => '2026-03-01']);
        SubscriptionPayment::create(['shop_id' => $shopB->id, 'plan' => 'monthly', 'amount' => 1000, 'month' => '2026-04', 'method' => 'cash', 'paid_on' => '2026-04-01']);

        $this->actingAs($admin, 'admin')->post("/admin/ambassadors/{$ambassador->id}/mark-paid", ['month' => '2026-03'])->assertRedirect();

        $this->assertSame('paid', AmbassadorCommission::where('month', '2026-03')->first()->status);
        $this->assertSame('unpaid', AmbassadorCommission::where('month', '2026-04')->first()->status);
    }

    /**
     * The list view only shows who an ambassador is (name/employee
     * ID/contact) — rates, shops-brought, and unpaid total are real data,
     * still fully visible on the ambassador's own Show page, just not on
     * this admin list anymore (Khaled's explicit ask).
     */
    public function test_ambassador_index_only_shows_identity_fields_not_rates_or_totals(): void
    {
        [$shop] = $this->createShopWithOwner();
        $ambassador = Ambassador::create(['name' => 'Karim', 'employee_id' => 'EMP-1', 'one_time_bonus' => 500, 'recurring_percent' => 10]);
        $this->actingAs($this->superAdmin(), 'admin')->post("/admin/shops/{$shop->id}/ambassador", ['brought_by_ambassador_id' => $ambassador->id]);

        $response = $this->actingAs($this->superAdmin(), 'admin')->get('/admin/ambassadors');

        $response->assertInertia(fn ($page) => $page
            ->where('ambassadors.0.name', 'Karim')
            ->where('ambassadors.0.employee_id', 'EMP-1')
            ->missing('ambassadors.0.unpaid_total')
            ->missing('ambassadors.0.one_time_bonus')
            ->missing('ambassadors.0.recurring_percent')
            ->missing('ambassadors.0.brought_shops_count')
        );
    }
}
