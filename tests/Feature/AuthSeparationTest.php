<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AuthSeparationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_shop_user_cannot_access_admin_routes(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/admin/dashboard');

        // EnsureAdmin middleware bounces anyone not on the admin guard to admin login
        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_login_does_not_authenticate_the_web_guard(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password']);

        $this->post('/admin/login', ['email' => 'admin@test.com', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_shop_login_does_not_authenticate_the_admin_guard(): void
    {
        [, $owner] = $this->createShopWithOwner(userAttrs: ['phone' => '01900000001']);
        $owner->forceFill(['password' => bcrypt('secret123')])->save();

        $this->post('/login', ['login' => '01900000001', 'password' => 'secret123'])
            ->assertRedirect(route('app.home'));

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertFalse(Auth::guard('admin')->check());
    }

    public function test_admin_can_reach_admin_dashboard_and_shop_owner_cannot_reach_it_with_shop_credentials(): void
    {
        Admin::create(['name' => 'Admin', 'email' => 'admin2@test.com', 'password' => 'password']);

        // wrong guard entirely: shop credentials submitted to the admin login form
        [, $owner] = $this->createShopWithOwner(userAttrs: ['phone' => '01900000002', 'email' => null]);
        $owner->forceFill(['password' => bcrypt('secret123')])->save();

        $this->post('/admin/login', ['email' => 'notarealadmin@test.com', 'password' => 'secret123'])
            ->assertSessionHasErrors();

        $this->assertFalse(Auth::guard('admin')->check());
    }
}
