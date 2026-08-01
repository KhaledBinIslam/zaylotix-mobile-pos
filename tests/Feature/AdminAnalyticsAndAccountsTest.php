<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AdminAnalyticsAndAccountsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function superAdmin(): Admin
    {
        return Admin::create(['name' => 'Super', 'email' => 'analytics-'.uniqid().'@test.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    public function test_analytics_aggregates_sales_across_every_shop(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Sale::create(['shop_id' => $shopA->id, 'invoice_no' => 'A-1', 'date' => now()->toDateString(), 'time' => '10:00:00', 'subtotal' => 100, 'discount' => 0, 'vat' => 0, 'total' => 100, 'profit' => 20, 'payment_mode' => 'cash']);
        Sale::create(['shop_id' => $shopB->id, 'invoice_no' => 'B-1', 'date' => now()->toDateString(), 'time' => '10:00:00', 'subtotal' => 200, 'discount' => 0, 'vat' => 0, 'total' => 200, 'profit' => 40, 'payment_mode' => 'cash']);

        $response = $this->actingAs($this->superAdmin(), 'admin')->get('/admin/analytics');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.salesToday', 300)
            ->where('stats.billsToday', 2)
            ->where('stats.activeShopsToday', 2)
        );
    }

    public function test_admin_can_create_a_new_admin_account(): void
    {
        $response = $this->actingAs($this->superAdmin(), 'admin')->post('/admin/admins', [
            'name' => 'New Support', 'email' => 'newsupport@test.com', 'password' => 'password123', 'role' => 'support',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('admins', ['email' => 'newsupport@test.com', 'role' => 'support']);
    }

    public function test_system_health_reports_database_connectivity(): void
    {
        $response = $this->actingAs($this->superAdmin(), 'admin')->get('/admin/system-health');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('db.ok', true));
    }

    public function test_activity_log_records_shop_creation(): void
    {
        $admin = $this->superAdmin();
        $businessType = \App\Models\BusinessType::create(['slug' => 'grocery-log', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);

        $this->actingAs($admin, 'admin')->post('/admin/shops', [
            'business_type_id' => $businessType->id,
            'name' => 'Logged Shop', 'phone' => '01755500001',
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addDays(14)->toDateString(),
            'owner_password' => 'password123',
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/activity-log');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('logs.data.0.action', 'shop.create')
        );
    }
}
