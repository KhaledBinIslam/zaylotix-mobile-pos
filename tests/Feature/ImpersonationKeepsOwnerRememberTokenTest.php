<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park, 2026-11-24: both branch owners kept being "logged out again
 * and again" on their phones. Cause: ImpersonationController::stop() used
 * Auth::logout(), which cycles the user's remember_token in the database —
 * so every time the admin stopped viewing a shop, that owner's remember-me
 * cookie on every other device became invalid, and the next session
 * expiry or app restart dumped them on the login page. Stopping an
 * impersonation must end only the admin browser's session.
 */
class ImpersonationKeepsOwnerRememberTokenTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_stopping_an_impersonation_leaves_the_owners_remember_token_alone(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $owner->forceFill(['remember_token' => 'owners-phone-is-logged-in-with-this'])->save();
        $admin = Admin::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/impersonate")->assertRedirect();
        $this->assertTrue(Auth::guard('web')->check(), 'admin is now acting as the owner');

        $this->post('/admin/impersonate/stop')->assertRedirect(route('admin.shops.index'));

        $this->assertFalse(Auth::guard('web')->check(), 'the impersonation session itself is over');
        $this->assertSame('owners-phone-is-logged-in-with-this', $owner->fresh()->remember_token,
            "the owner's own devices must keep their remember-me login");
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'impersonate.stop']);
    }

    public function test_starting_an_impersonation_does_not_touch_the_owners_remember_token_either(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $owner->forceFill(['remember_token' => 'untouched'])->save();
        $admin = Admin::create(['name' => 'Super', 'email' => 'super2@test.com', 'password' => 'password', 'role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->post("/admin/shops/{$shop->id}/impersonate")->assertRedirect();

        $this->assertSame('untouched', $owner->fresh()->remember_token);
    }
}
