<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Exercises the branch-switch flow through real HTTP requests (full
 * middleware stack), not tinker/reflection — a gap that let a real bug
 * through undetected: EnsureShopUser used to call Tenancy::set($user->shop_id)
 * unconditionally, which binds a container override that Tenancy::id()
 * checks BEFORE its own session-based branch logic, silently making
 * BranchController::switch() a no-op for the rest of the app. Verified live
 * via curl against the running dev server, then locked in here.
 */
class BranchSwitchingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        return Shop::create([
            'parent_shop_id' => $main->id,
            'name' => $main->name.' - Branch',
            'phone' => '019'.random_int(10000000, 99999999),
            'sales_mode' => 'both',
            'lang' => 'bn',
            'plan' => 'trial',
            'status' => 'active',
            'subscription_start' => now()->toDateString(),
            'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0,
            'bank_balance' => 0,
            'capital' => 0,
            'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
    }

    public function test_owner_switching_to_a_branch_makes_subsequent_requests_resolve_to_that_branchs_data(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        Product::create(['shop_id' => $main->id, 'name' => 'Main Shop Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Product::create(['shop_id' => $branch->id, 'name' => 'Branch Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->actingAs($owner, 'web')
            ->post("/app/branches/{$branch->id}/switch")
            ->assertRedirect(route('app.home'));

        // the real, previously-broken assertion: a totally separate
        // subsequent request must still see the branch, not silently fall
        // back to the owner's own shop_id
        $response = $this->actingAs($owner, 'web')->get('/app/pos');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('shop.id', $branch->id)
            ->has('products', 1)
            ->where('products.0.name', 'Branch Product')
        );
    }

    public function test_staff_cannot_switch_branches(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        $staff = User::create(['shop_id' => $main->id, 'name' => 'Cashier', 'phone' => '01911111111', 'password' => 'password', 'role' => 'cashier', 'lang' => 'bn']);

        $this->actingAs($staff, 'web')
            ->post("/app/branches/{$branch->id}/switch")
            ->assertForbidden();
    }

    public function test_switching_to_a_shop_that_is_not_actually_a_sibling_is_rejected(): void
    {
        [, $owner] = $this->createShopWithOwner();
        [$unrelatedShop] = $this->createShopWithOwner(); // a completely different business

        $this->actingAs($owner, 'web')
            ->post("/app/branches/{$unrelatedShop->id}/switch")
            ->assertForbidden();
    }

    public function test_switching_back_to_the_main_shop_restores_the_owners_own_data(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        Product::create(['shop_id' => $main->id, 'name' => 'Main Shop Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->actingAs($owner, 'web')->post("/app/branches/{$branch->id}/switch")->assertRedirect();
        $this->actingAs($owner, 'web')->post("/app/branches/{$main->id}/switch")->assertRedirect();

        $response = $this->actingAs($owner, 'web')->get('/app/pos');
        $response->assertOk()->assertInertia(fn ($page) => $page->where('shop.id', $main->id));
    }
}
