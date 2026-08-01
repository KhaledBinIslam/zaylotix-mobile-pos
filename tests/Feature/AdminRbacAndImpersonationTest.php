<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\BusinessType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AdminRbacAndImpersonationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_support_admin_cannot_delete_a_shop(): void
    {
        $support = Admin::create(['name' => 'Support', 'email' => 'support@test.com', 'password' => 'password', 'role' => 'support']);
        [$shop] = $this->createShopWithOwner();

        $response = $this->actingAs($support, 'admin')->delete("/admin/shops/{$shop->id}", ['confirm_name' => $shop->name]);

        $response->assertStatus(403);
        $this->assertNotNull($shop->fresh());
    }

    public function test_a_super_admin_can_delete_a_shop(): void
    {
        $superAdmin = Admin::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super_admin']);
        [$shop] = $this->createShopWithOwner();

        $response = $this->actingAs($superAdmin, 'admin')->delete("/admin/shops/{$shop->id}", ['confirm_name' => $shop->name]);

        $response->assertRedirect();
        $this->assertNull($shop->fresh());
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'shop.delete']);
    }

    public function test_a_support_admin_cannot_manage_business_types_or_other_admins(): void
    {
        $support = Admin::create(['name' => 'Support', 'email' => 'support2@test.com', 'password' => 'password', 'role' => 'support']);

        $this->actingAs($support, 'admin')->get('/admin/business-types')->assertStatus(403);
        $this->actingAs($support, 'admin')->get('/admin/admins')->assertStatus(403);
    }

    public function test_a_support_admin_can_view_analytics_and_system_health(): void
    {
        $support = Admin::create(['name' => 'Support', 'email' => 'support3@test.com', 'password' => 'password', 'role' => 'support']);

        $this->actingAs($support, 'admin')->get('/admin/analytics')->assertOk();
        $this->actingAs($support, 'admin')->get('/admin/system-health')->assertOk();
        $this->actingAs($support, 'admin')->get('/admin/activity-log')->assertOk();
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $superAdmin = Admin::create(['name' => 'Super', 'email' => 'onlysuper@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $response = $this->actingAs($superAdmin, 'admin')->put("/admin/admins/{$superAdmin->id}", [
            'name' => 'Super', 'email' => 'onlysuper@test.com', 'role' => 'support',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_a_super_admin_cannot_delete_their_own_account(): void
    {
        $superAdmin = Admin::create(['name' => 'Super', 'email' => 'onlysuper2@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $response = $this->actingAs($superAdmin, 'admin')->delete("/admin/admins/{$superAdmin->id}");

        $response->assertSessionHasErrors('admin');
        $this->assertNotNull($superAdmin->fresh());
    }

    public function test_a_super_admin_can_delete_a_different_super_admin_when_more_than_one_exists(): void
    {
        $actingSuperAdmin = Admin::create(['name' => 'Acting Super', 'email' => 'actingsuper@test.com', 'password' => 'password', 'role' => 'super_admin']);
        $otherSuperAdmin = Admin::create(['name' => 'Other Super', 'email' => 'othersuper@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $response = $this->actingAs($actingSuperAdmin, 'admin')->delete("/admin/admins/{$otherSuperAdmin->id}");

        $response->assertRedirect();
        $this->assertNull($otherSuperAdmin->fresh());
    }

    public function test_impersonation_logs_in_as_the_shop_owner_and_is_audit_logged(): void
    {
        $admin = Admin::create(['name' => 'Support', 'email' => 'imp@test.com', 'password' => 'password', 'role' => 'support']);
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/impersonate");

        $response->assertRedirect(route('app.home'));
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertAuthenticatedAs($admin, 'admin'); // admin guard session untouched
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'impersonate.start']);
    }

    public function test_stopping_impersonation_logs_out_the_web_guard_and_keeps_the_admin_session(): void
    {
        $admin = Admin::create(['name' => 'Support', 'email' => 'imp2@test.com', 'password' => 'password', 'role' => 'support']);
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/impersonate");

        $response = $this->post('/admin/impersonate/stop');

        $response->assertRedirect(route('admin.shops.index'));
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_impersonation_banner_prop_is_shared_while_impersonating(): void
    {
        $admin = Admin::create(['name' => 'Support Person', 'email' => 'imp3@test.com', 'password' => 'password', 'role' => 'support']);
        [$shop, $owner] = $this->createShopWithOwner();
        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/impersonate");

        $response = $this->get('/app/home');

        $response->assertInertia(fn ($page) => $page->where('impersonating.adminName', 'Support Person'));
    }

    public function test_signup_business_types_endpoint_still_works_without_super_admin_gate(): void
    {
        // sanity check that the public self-serve signup page (which lists
        // business types) was NOT accidentally moved behind the admin guard
        BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);

        $this->get('/signup')->assertOk();
    }
}
