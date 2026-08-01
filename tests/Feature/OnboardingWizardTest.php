<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_new_owner_is_redirected_to_onboarding_from_home(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['onboarded_at' => null]);

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertRedirect(route('app.onboarding'));
    }

    public function test_an_already_onboarded_owner_goes_straight_to_home(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['onboarded_at' => now()]);

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertOk();
    }

    public function test_a_cashier_is_never_sent_to_onboarding(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['onboarded_at' => null]);
        $cashier = \App\Models\User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01800000000',
            'password' => 'password', 'role' => 'staff', 'lang' => 'bn',
        ]);

        $response = $this->actingAs($cashier, 'web')->get('/app/home');

        $response->assertOk();
    }

    public function test_completing_onboarding_marks_the_shop_and_returns_home(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['onboarded_at' => null]);

        $this->actingAs($owner, 'web')->post('/app/onboarding/complete')
            ->assertRedirect(route('app.home'));

        $this->assertNotNull($shop->fresh()->onboarded_at);
        $this->actingAs($owner, 'web')->get('/app/home')->assertOk();
    }

    public function test_onboarding_page_is_reachable_and_shows_the_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['onboarded_at' => null]);

        $response = $this->actingAs($owner, 'web')->get('/app/onboarding');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Onboarding/Index')
            ->where('shop.id', $shop->id)
        );
    }
}
