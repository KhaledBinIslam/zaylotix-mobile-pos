<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled's explicit request: sellers want to start selling the instant the
 * app opens, not go through a dashboard first. POS is the new default
 * post-login landing page — but only for whoever can actually reach it
 * (see DefaultLandingRoute); a cashier without the 'pos' permission must
 * still land somewhere reachable, not get redirected straight into a 403.
 */
class DefaultLandingRouteTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_login_lands_on_pos(): void
    {
        [, $owner] = $this->createShopWithOwner(userAttrs: ['password' => 'secret1234']);

        $this->post('/login', ['login' => $owner->phone, 'password' => 'secret1234'])
            ->assertRedirect(route('app.pos'));
    }

    public function test_cashier_with_pos_permission_lands_on_pos(): void
    {
        [$shop] = $this->createShopWithOwner();
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900001111',
            'password' => 'secret1234', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn',
        ]);

        $this->post('/login', ['login' => '01900001111', 'password' => 'secret1234'])
            ->assertRedirect(route('app.pos'));
    }

    public function test_cashier_without_pos_permission_lands_on_home_not_a_403(): void
    {
        [$shop] = $this->createShopWithOwner();
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900002222',
            'password' => 'secret1234', 'role' => 'staff', 'permissions' => ['sales_history'], 'lang' => 'bn',
        ]);

        $this->post('/login', ['login' => '01900002222', 'password' => 'secret1234'])
            ->assertRedirect(route('app.home'));

        $this->actingAs($cashier, 'web')->get('/app/home')->assertOk();
        $this->actingAs($cashier, 'web')->get('/app/pos')->assertForbidden();
    }

    public function test_a_brand_new_unonboarded_owner_still_sees_onboarding_first_via_pos(): void
    {
        [, $owner] = $this->createShopWithOwner(['onboarded_at' => null]);

        // the login redirect itself still points at POS (DefaultLandingRoute
        // doesn't know about onboarding) - PosController is what catches it
        $this->actingAs($owner, 'web')->get('/app/pos')->assertRedirect(route('app.onboarding'));
    }

    /** Profit/margin moved off Home entirely to Reports - see HomeController's own comment. */
    public function test_home_no_longer_sends_profit_figures(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertInertia(fn ($page) => $page
            ->component('App/Home')
            ->has('todaySale')
            ->has('outOfStockCount')
            ->missing('todayProfit')
            ->missing('weekProfit')
            ->missing('monthProfit'));
    }
}
