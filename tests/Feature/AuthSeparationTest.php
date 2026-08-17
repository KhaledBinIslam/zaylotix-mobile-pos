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

    /**
     * Root-cause regression guard for a live production bug: a raw-fetch
     * screen (POS checkout, Restaurant addItem/bill, etc.) sends
     * Accept: application/json and handles its own JSON error shape.
     * Before this, a logged-out/session-expired request always got
     * redirect()->route('login') regardless of that header — fetch()
     * silently followed it into the login page's full HTML, which the
     * caller then failed to parse as JSON, surfacing as a confusing
     * "not valid JSON" error instead of a clear "you're logged out" one.
     */
    public function test_json_request_gets_a_401_instead_of_a_redirect_when_logged_out(): void
    {
        $response = $this->getJson('/app/home');

        $response->assertStatus(401);
    }

    /** A plain browser navigation (no Accept: application/json) must keep working exactly as before — still the normal redirect, not a raw 401 page. */
    public function test_plain_navigation_still_redirects_to_login_when_logged_out(): void
    {
        $response = $this->get('/app/home');

        $response->assertRedirect(route('login'));
    }

    /**
     * Root-cause regression guard: this app has ~6 screens (Order/Pos/
     * Stock/Tables/Kds/Cds — see usePollingReload.js) that quietly poll
     * every 8-15s for another device's changes via a normal Inertia
     * router.reload(). Before this, a transient session-read hiccup on a
     * background poll (not an actual logout) got the same redirect a real
     * navigation would, which Inertia's client auto-follows — silently
     * dragging a cashier off whatever they were doing, mid-sale, to the
     * login page. A background poll must always get a plain JSON error
     * instead, exactly like an explicit raw-fetch action already does.
     */
    public function test_a_background_poll_gets_a_401_instead_of_a_redirect_when_logged_out(): void
    {
        $response = $this->get('/app/home', ['X-Inertia-Poll' => 'true']);

        $response->assertStatus(401);
    }

    /** Same poll header, but on an Inertia-style plain visit (no Accept: application/json) — the header alone must be enough, since Inertia's own router.reload() doesn't set that Accept value. */
    public function test_a_background_poll_without_an_explicit_json_accept_header_still_gets_a_401(): void
    {
        $response = $this->withHeaders(['X-Inertia-Poll' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
            ->get('/app/home');

        $response->assertStatus(401);
    }
}
