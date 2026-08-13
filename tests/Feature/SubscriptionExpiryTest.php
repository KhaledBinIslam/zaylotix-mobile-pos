<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class SubscriptionExpiryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_command_deactivates_shops_past_their_expiry_date(): void
    {
        [$expiredShop] = $this->createShopWithOwner(['subscription_expiry' => now()->subDay()->toDateString()]);
        [$activeShop] = $this->createShopWithOwner(['subscription_expiry' => now()->addDay()->toDateString()]);

        $this->artisan('zaylotix:expire-subscriptions')->assertExitCode(0);

        $this->assertSame('inactive', $expiredShop->fresh()->status);
        $this->assertSame('active', $activeShop->fresh()->status);
    }

    public function test_expired_shop_user_cannot_log_in(): void
    {
        [, $owner] = $this->createShopWithOwner([
            'subscription_expiry' => now()->subDay()->toDateString(),
        ], [
            'phone' => '01900000010',
        ]);
        $owner->forceFill(['password' => bcrypt('secret123')])->save();

        // subscription is expired but status hasn't been flipped by the scheduler yet —
        // login must still check isActive() directly, not just the status column
        $response = $this->post('/login', ['login' => '01900000010', 'password' => 'secret123']);

        $response->assertSessionHasErrors('login');
        $this->assertGuest('web');
    }

    public function test_deactivated_shop_user_session_is_terminated_on_next_request(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/app/home')->assertOk();

        $shop->update(['status' => 'inactive']);

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    /**
     * Root-cause regression guard for a live production bug: a raw-fetch
     * screen (POS checkout, Restaurant addItem/bill, etc.) sends
     * Accept: application/json and handles its own JSON error shape.
     * Before this, a deactivated shop always got redirect()->route('login')
     * regardless of that header — fetch() silently followed it into the
     * login page's full HTML, which the caller then failed to parse as
     * JSON, surfacing as a confusing "not valid JSON" error instead of a
     * clear "you're logged out" one. This must come back as a real 401
     * JSON response for any request that says it wants JSON.
     */
    public function test_json_request_gets_a_401_instead_of_a_redirect_when_shop_is_inactive(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['status' => 'inactive']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/home');

        $response->assertStatus(401);
        $this->assertGuest('web');
    }
}
