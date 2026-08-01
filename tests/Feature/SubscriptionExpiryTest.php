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
}
