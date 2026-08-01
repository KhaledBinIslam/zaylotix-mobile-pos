<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Not a behavior test — just proves every page controller resolves to a Vue
 * component that actually exists in the Vite build, so a typo'd
 * Inertia::render() path fails CI instead of surfacing as a blank screen
 * only in the browser.
 */
class PageRenderTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_every_shop_app_page_renders(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['barcode_printing', 'expenses', 'accounts', 'reports', 'activity_log', 'suppliers', 'serial_tracking', 'restaurant_tables'] as $key) {
            $this->grantFeature($shop, $key);
        }

        foreach ([
            '/app/home', '/app/pos', '/app/stock', '/app/customers', '/app/expenses',
            '/app/accounts', '/app/reports', '/app/more', '/app/sales', '/app/barcode-labels',
            '/app/activity', '/app/suppliers', '/app/serials', '/app/restaurant/tables',
        ] as $url) {
            $this->actingAs($owner, 'web')->get($url)->assertOk();
        }
    }

    public function test_every_admin_page_renders(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'render-admin@test.com', 'password' => 'password']);
        [$shop] = $this->createShopWithOwner();

        $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/shops')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/shops/create')->assertOk();
        $this->actingAs($admin, 'admin')->get("/admin/shops/{$shop->id}/edit")->assertOk();
        $this->actingAs($admin, 'admin')->get("/admin/shops/{$shop->id}")->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/business-types')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/subscriptions')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/features')->assertOk();
    }

    public function test_login_pages_render_for_guests(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/admin/login')->assertOk();
    }
}
