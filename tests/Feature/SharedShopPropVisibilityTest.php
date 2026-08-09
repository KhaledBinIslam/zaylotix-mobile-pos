<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Security-audit fix: the `shop` prop HandleInertiaRequests shares on every
 * page used to be the raw Shop model — cash_balance/bank_balance/capital/
 * monthly_fee included — regardless of the viewing user's own permissions.
 * A cashier the owner never granted `accounts` to could still read those
 * figures from any page's Inertia payload (devtools/view-source), even
 * though the Accounts page itself was correctly blocked server-side.
 */
class SharedShopPropVisibilityTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_sees_the_financial_fields(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 500, 'bank_balance' => 200, 'capital' => 1000, 'monthly_fee' => 999]);

        $this->actingAs($owner, 'web')->get('/app/home')->assertInertia(fn ($page) => $page
            ->where('shop.cash_balance', '500.00')
            ->where('shop.bank_balance', '200.00')
            ->where('shop.capital', '1000.00')
            ->where('shop.monthly_fee', '999.00')
        );
    }

    public function test_staff_without_accounts_permission_does_not_see_the_financial_fields(): void
    {
        [$shop] = $this->createShopWithOwner(['cash_balance' => 500, 'bank_balance' => 200, 'capital' => 1000, 'monthly_fee' => 999]);
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '018'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);

        $this->actingAs($cashier, 'web')->get('/app/home')->assertInertia(fn ($page) => $page
            ->where('shop.name', $shop->name) // ordinary fields still present, page isn't broken
            ->missing('shop.cash_balance')
            ->missing('shop.bank_balance')
            ->missing('shop.capital')
            ->missing('shop.monthly_fee')
        );
    }

    public function test_staff_with_accounts_permission_still_sees_the_financial_fields(): void
    {
        [$shop] = $this->createShopWithOwner(['cash_balance' => 500, 'bank_balance' => 200, 'capital' => 1000, 'monthly_fee' => 999]);
        $this->grantFeature($shop, 'accounts');
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Trusted Cashier', 'phone' => '018'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['accounts'], 'lang' => 'bn']);

        $this->actingAs($cashier, 'web')->get('/app/home')->assertInertia(fn ($page) => $page
            ->where('shop.cash_balance', '500.00')
        );
    }

    /** Same leak, different page — More.vue is reachable by any staff with no permission gate at all. */
    public function test_more_page_also_redacts_the_financial_fields_for_a_restricted_staff(): void
    {
        [$shop] = $this->createShopWithOwner(['cash_balance' => 500, 'bank_balance' => 200, 'capital' => 1000, 'monthly_fee' => 999]);
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '018'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);

        $this->actingAs($cashier, 'web')->get('/app/more')->assertInertia(fn ($page) => $page
            ->missing('shop.cash_balance')
            ->missing('shop.bank_balance')
            ->missing('shop.capital')
            ->missing('shop.monthly_fee')
        );
    }

    /** Same leak, mobile API — Api\AuthController::me()/login() used to ship the raw model too. */
    public function test_mobile_api_me_also_redacts_the_financial_fields_for_a_restricted_staff(): void
    {
        [$shop] = $this->createShopWithOwner(['cash_balance' => 500, 'bank_balance' => 200, 'capital' => 1000, 'monthly_fee' => 999]);
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '018'.random_int(10000000, 99999999), 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn']);

        $response = $this->actingAs($cashier, 'sanctum')->getJson('/api/me');

        $response->assertOk();
        $this->assertArrayNotHasKey('cash_balance', $response->json('shop'));
        $this->assertArrayNotHasKey('bank_balance', $response->json('shop'));
        $this->assertArrayNotHasKey('capital', $response->json('shop'));
        $this->assertArrayNotHasKey('monthly_fee', $response->json('shop'));
    }
}
