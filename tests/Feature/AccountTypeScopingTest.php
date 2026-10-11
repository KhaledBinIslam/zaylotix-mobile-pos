<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BusinessType;
use App\Models\Sale;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * account_type (demo/trial/paid/internal) classifies a shop for Dashboard/
 * Analytics purposes only — it never affects login, features, or anything
 * the shop itself can do. Only "paid" counts toward revenue/shop-count
 * figures (Shop::scopePaidAccounts()); existing shops default to "paid" so
 * this changes zero numbers for anyone until Khaled reclassifies them
 * himself.
 */
class AccountTypeScopingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'acct-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    public function test_a_shop_defaults_to_paid_account_type(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->assertSame('paid', $shop->fresh()->account_type);
    }

    public function test_a_self_signup_shop_is_classified_as_trial(): void
    {
        $businessType = \App\Models\BusinessType::create(['slug' => 'general', 'label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);

        $this->post('/signup', [
            'name' => 'New Shop', 'phone' => '01777777777', 'business_type_id' => $businessType->id,
            'owner_name' => 'Owner', 'password' => '1234',
        ])->assertRedirect();

        $shop = Shop::where('phone', '01777777777')->first();
        $this->assertSame('trial', $shop->account_type);
    }

    public function test_demo_and_trial_shops_are_excluded_from_dashboard_and_analytics_counts(): void
    {
        [$paidShop] = $this->createShopWithOwner(['account_type' => 'paid']);
        [$demoShop] = $this->createShopWithOwner(['account_type' => 'demo']);
        [$trialShop] = $this->createShopWithOwner(['account_type' => 'trial']);

        Sale::create(['shop_id' => $paidShop->id, 'invoice_no' => 'P-1', 'date' => now()->toDateString(), 'time' => '10:00:00', 'subtotal' => 100, 'discount' => 0, 'vat' => 0, 'total' => 100, 'profit' => 20, 'payment_mode' => 'cash']);
        Sale::create(['shop_id' => $demoShop->id, 'invoice_no' => 'D-1', 'date' => now()->toDateString(), 'time' => '10:00:00', 'subtotal' => 500, 'discount' => 0, 'vat' => 0, 'total' => 500, 'profit' => 90, 'payment_mode' => 'cash']);
        Sale::create(['shop_id' => $trialShop->id, 'invoice_no' => 'T-1', 'date' => now()->toDateString(), 'time' => '10:00:00', 'subtotal' => 300, 'discount' => 0, 'vat' => 0, 'total' => 300, 'profit' => 50, 'payment_mode' => 'cash']);

        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertInertia(fn ($page) => $page
            ->where('stats.totalShops', 1)
            ->where('stats.activeShops', 1)
        );

        $this->actingAs($admin, 'admin')->get('/admin/analytics')->assertInertia(fn ($page) => $page
            ->where('stats.salesToday', 100)
            ->where('stats.billsToday', 1)
            ->where('stats.totalShops', 1)
            ->where('stats.activeShopsToday', 1)
        );
    }

    public function test_admin_shops_list_exposes_account_type_for_filtering(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'internal']);

        $this->actingAs($this->superAdmin(), 'admin')->get('/admin/shops')->assertInertia(fn ($page) => $page
            ->where('shops.0.account_type', 'internal')
        );
    }

    public function test_admin_can_reclassify_a_shops_account_type(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$shop] = $this->createShopWithOwner(['account_type' => 'paid', 'business_type_id' => $type->id]);
        $admin = $this->superAdmin();

        $payload = [
            'name' => $shop->name, 'phone' => $shop->phone, 'business_type_id' => $shop->business_type_id,
            'sales_mode' => $shop->sales_mode, 'plan' => $shop->plan, 'status' => $shop->status,
            'subscription_start' => $shop->subscription_start->toDateString(), 'subscription_expiry' => $shop->subscription_expiry->toDateString(),
            'lang' => $shop->lang, 'account_type' => 'internal',
        ];

        $this->actingAs($admin, 'admin')->put("/admin/shops/{$shop->id}", $payload)->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertSame('internal', $shop->fresh()->account_type);
    }

    /** A test payload that omits account_type entirely (old client, old test) must never silently wipe an existing classification. */
    public function test_updating_a_shop_without_sending_account_type_leaves_it_unchanged(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'সাধারণ', 'label_en' => 'General', 'is_active' => true]);
        [$shop] = $this->createShopWithOwner(['account_type' => 'demo', 'business_type_id' => $type->id]);
        $admin = $this->superAdmin();

        $payload = [
            'name' => $shop->name, 'phone' => $shop->phone, 'business_type_id' => $shop->business_type_id,
            'sales_mode' => $shop->sales_mode, 'plan' => $shop->plan, 'status' => $shop->status,
            'subscription_start' => $shop->subscription_start->toDateString(), 'subscription_expiry' => $shop->subscription_expiry->toDateString(),
            'lang' => $shop->lang,
        ];

        $this->actingAs($admin, 'admin')->put("/admin/shops/{$shop->id}", $payload)->assertSessionDoesntHaveErrors()->assertRedirect();

        $this->assertSame('demo', $shop->fresh()->account_type);
    }
}
