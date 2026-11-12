<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Khaled: turn off public self-registration. */
class SignupEnabledToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_is_open_by_default(): void
    {
        $this->assertTrue(SiteSetting::current()->signup_enabled);
        $this->get('/signup')->assertOk();
    }

    public function test_the_signup_page_404s_once_disabled(): void
    {
        SiteSetting::current()->update(['signup_enabled' => false]);

        $this->get('/signup')->assertNotFound();
    }

    public function test_signup_submission_is_refused_once_disabled(): void
    {
        SiteSetting::current()->update(['signup_enabled' => false]);

        $this->post('/signup', ['name' => 'X', 'phone' => '01700000001', 'owner_name' => 'X', 'password' => 'secret1'])
            ->assertNotFound();
        $this->assertDatabaseMissing('shops', ['phone' => '01700000001']);
    }

    public function test_login_page_hides_the_signup_link_once_disabled(): void
    {
        SiteSetting::current()->update(['signup_enabled' => false]);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('signupEnabled', false));
    }

    public function test_landing_page_hides_signup_ctas_once_disabled(): void
    {
        SiteSetting::current()->update(['signup_enabled' => false]);

        $this->get('/')->assertInertia(fn ($page) => $page->where('signupEnabled', false));
    }

    public function test_a_super_admin_can_toggle_signup_enabled(): void
    {
        $superAdmin = Admin::create(['name' => 'Super', 'email' => 'signuptoggle@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $this->actingAs($superAdmin, 'admin')
            ->patch('/admin/site-settings/signup-enabled', ['signup_enabled' => false])
            ->assertRedirect();

        $this->assertFalse(SiteSetting::current()->fresh()->signup_enabled);
    }

    public function test_a_support_admin_cannot_toggle_it(): void
    {
        // every site-settings route, including this one, sits inside
        // routes/admin.php's super.admin-only group
        $support = Admin::create(['name' => 'Support', 'email' => 'signuptoggle2@test.com', 'password' => 'password', 'role' => 'support']);

        $this->actingAs($support, 'admin')
            ->patch('/admin/site-settings/signup-enabled', ['signup_enabled' => false])
            ->assertStatus(403);

        $this->assertTrue(SiteSetting::current()->fresh()->signup_enabled);
    }
}
