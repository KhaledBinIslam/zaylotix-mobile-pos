<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Real production ask (Baby Park, 2026-11-20): two shops (a main shop and
 * its one branch) are actually ONE paying client who renews once, not
 * twice -- discovered live when the admin updated the main shop's
 * subscription_expiry and the branch kept showing its own, older date.
 * billing_follows_parent is opt-in (default false) so every other
 * branch's independent billing is completely unaffected -- see
 * Shop::subscriptionShop().
 */
class BranchFollowsParentSubscriptionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    protected function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'billing-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    protected function makeCombinedPair(): array
    {
        [$main, $owner] = $this->createShopWithOwner([
            'name' => 'Main Shop', 'subscription_expiry' => now()->addDays(10)->toDateString(), 'status' => 'active',
        ]);
        $branch = Shop::create([
            'parent_shop_id' => $main->id, 'billing_follows_parent' => true,
            'name' => 'Branch Shop', 'phone' => '018'.random_int(10000000, 99999999),
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => $main->plan, 'status' => 'active',
            'subscription_start' => now()->toDateString(),
            // deliberately stale/expired, to prove it's never consulted
            'subscription_expiry' => now()->subDays(5)->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);

        return [$main, $branch, $owner];
    }

    public function test_a_combined_branch_reports_its_parents_expiry_and_status_not_its_own(): void
    {
        [$main, $branch] = $this->makeCombinedPair();

        // branch's own column is already in the past (set above) -- if this
        // weren't resolved via the parent, both checks below would fail
        $this->assertTrue($branch->isActive());
        $this->assertSame($main->daysLeft(), $branch->daysLeft());
        $this->assertSame(10, $branch->daysLeft());
    }

    public function test_a_plain_branch_without_the_flag_keeps_its_own_independent_subscription(): void
    {
        [$main, $owner] = $this->createShopWithOwner(['subscription_expiry' => now()->addDays(10)->toDateString()]);
        $branch = Shop::create([
            'parent_shop_id' => $main->id, 'billing_follows_parent' => false,
            'name' => 'Independent Branch', 'phone' => '018'.random_int(10000000, 99999999),
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->subDays(5)->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);

        // its own expiry is in the past and the flag is off -- must be
        // independently expired, completely unaffected by the parent's
        $this->assertFalse($branch->isActive());
        $this->assertSame(-5, $branch->daysLeft());
    }

    public function test_toarrayforuser_shows_the_resolved_expiry_for_a_combined_branch(): void
    {
        [$main, $branch, $owner] = $this->makeCombinedPair();

        $data = $branch->toArrayForUser($owner);

        $this->assertSame($main->subscription_expiry->toDateString(), $data['subscription_expiry']);
    }

    public function test_admin_recording_a_payment_against_the_branch_extends_the_parent_instead(): void
    {
        [$main, $branch] = $this->makeCombinedPair();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')->post('/admin/subscriptions', [
            'shop_id' => $branch->id, 'plan' => 'monthly', 'amount' => 1000,
            'month' => now()->format('Y-m'), 'method' => 'cash',
            'paid_on' => now()->toDateString(), 'next_due' => now()->addMonth()->toDateString(),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertEquals(now()->addMonth()->toDateString(), $main->fresh()->subscription_expiry->toDateString());
        // the branch's own column is untouched -- it was never the target
        $this->assertNotEquals(now()->addMonth()->toDateString(), $branch->fresh()->subscription_expiry->toDateString());
    }

    public function test_admin_can_turn_on_billing_follows_parent_from_the_branch_edit_form(): void
    {
        $type = \App\Models\BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$main, $owner] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $branch = Shop::create([
            'parent_shop_id' => $main->id, 'business_type_id' => $type->id, 'name' => 'New Branch', 'phone' => '018'.random_int(10000000, 99999999),
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now(),
        ]);
        $admin = $this->admin();

        $payload = [
            'name' => $branch->name, 'phone' => $branch->phone, 'business_type_id' => $branch->business_type_id,
            'sales_mode' => $branch->sales_mode, 'plan' => $branch->plan, 'status' => $branch->status,
            'subscription_start' => $branch->subscription_start->toDateString(), 'subscription_expiry' => $branch->subscription_expiry->toDateString(),
            'lang' => $branch->lang, 'billing_follows_parent' => true,
        ];

        $this->actingAs($admin, 'admin')->put("/admin/shops/{$branch->id}", $payload)->assertSessionDoesntHaveErrors();

        $this->assertTrue($branch->fresh()->billing_follows_parent);
        $this->assertTrue($branch->fresh()->isActive());
    }

    public function test_expire_subscriptions_command_never_touches_a_combined_branchs_own_status(): void
    {
        [$main, $branch] = $this->makeCombinedPair();

        Artisan::call('zaylotix:expire-subscriptions');

        // the branch's own subscription_expiry was already in the past --
        // without the exclusion this command would have flipped its status
        $this->assertSame('active', $branch->fresh()->status);
        $this->assertTrue($branch->fresh()->isActive());
    }

    public function test_expire_subscriptions_command_still_expires_a_plain_shop_normally(): void
    {
        [$shop] = $this->createShopWithOwner(['subscription_expiry' => now()->subDays(2)->toDateString()]);

        Artisan::call('zaylotix:expire-subscriptions');

        $this->assertSame('inactive', $shop->fresh()->status);
    }
}
