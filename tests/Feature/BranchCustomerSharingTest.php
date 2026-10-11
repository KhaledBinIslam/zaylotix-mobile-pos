<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Branch phase 2: a customer's due/loyalty/visit history follows the
 * person, not which branch's till they happened to be standing at —
 * Customer now uses BusinessScope (see Customer::class) instead of the
 * usual exact-shop_id TenantScope. For any shop with no branches at all
 * (the overwhelming majority), businessShopIds() resolves to just that one
 * shop, so nothing here changes existing single-shop behavior — confirmed
 * by the full suite's pre-existing tenant-isolation tests all still passing
 * unchanged.
 */
class BranchCustomerSharingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_a_customer_registered_at_the_main_shop_is_found_by_phone_from_a_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        $customer = Customer::create(['shop_id' => $main->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 100]);

        \App\Support\Tenancy::set($branch->id);
        $found = Customer::where('phone', '01711111111')->first();

        $this->assertNotNull($found);
        $this->assertSame($customer->id, $found->id);
        $this->assertEquals(100, (float) $found->due);
    }

    public function test_due_collected_at_a_branch_is_visible_from_the_main_shop_and_every_sibling(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($main, 'accounts');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $customer = Customer::create(['shop_id' => $main->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 500]);

        // collect 200 due while "at" branch A
        \App\Support\Tenancy::set($branchA->id);
        $branchAOwner = User::withoutGlobalScopes()->where('shop_id', $branchA->id)->first();
        $this->actingAs($branchAOwner, 'web')->post("/app/customers/{$customer->id}/payments", ['amount' => 200, 'method' => 'cash'])->assertRedirect();

        // the SAME row, due reduced, visible from the main shop and branch B too
        $this->assertEquals(300, (float) $customer->fresh()->due);

        \App\Support\Tenancy::set($main->id);
        $this->assertEquals(300, (float) Customer::find($customer->id)->due);

        \App\Support\Tenancy::set($branchB->id);
        $this->assertEquals(300, (float) Customer::find($customer->id)->due);
    }

    public function test_an_unrelated_shops_customer_stays_invisible_across_the_business(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        [$unrelated] = $this->createShopWithOwner(); // a totally different business
        Customer::create(['shop_id' => $unrelated->id, 'name' => 'Stranger', 'phone' => '01799999999']);

        \App\Support\Tenancy::set($branch->id);
        $found = Customer::where('phone', '01799999999')->first();

        $this->assertNull($found);
    }

    public function test_checkout_at_a_branch_finds_and_reuses_an_existing_business_customer_not_a_duplicate(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'loyalty_points');
        $branch = $this->createBranch($main);
        $this->grantFeature($branch, 'loyalty_points');
        $branchOwner = User::withoutGlobalScopes()->where('shop_id', $branch->id)->first();
        Customer::create(['shop_id' => $main->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 0, 'visits' => 3]);
        $product = Product::create(['shop_id' => $branch->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);

        $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 70]],
            'customer_phone' => '01711111111',
            'customer_name' => 'Karim',
        ])->assertOk();

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('phone', '01711111111')->count()); // not duplicated
    }

    /** CustomerPolicy: a branch user acting on a customer whose own `shop_id` points at a sibling branch (not their own) must still be authorized — same business, not same exact shop. */
    public function test_a_branch_user_can_collect_payment_from_a_customer_registered_at_a_sibling_branch(): void
    {
        [$main, ] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($main, 'accounts');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();
        $customer = Customer::create(['shop_id' => $branchA->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 300]);

        \App\Support\Tenancy::set($branchB->id);
        $this->actingAs($branchBOwner, 'web')
            ->post("/app/customers/{$customer->id}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertRedirect();

        $this->assertEquals(200, (float) $customer->fresh()->due);
    }

    /**
     * Same scenario, a genuinely unrelated shop — route-model-binding itself
     * resolves a Customer through its own global scope (BusinessScope), so
     * an unrelated shop's request never even finds the row to reach
     * CustomerPolicy at all; this comes back 404, the same "cross-tenant
     * 404, not 403" pattern every other tenant-scoped model already has.
     */
    public function test_an_unrelated_shops_user_cannot_reach_another_businesss_customer_at_all(): void
    {
        [$main] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $main->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 300]);
        [$unrelated, $unrelatedOwner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($unrelated, 'accounts');

        \App\Support\Tenancy::set($unrelated->id);
        $this->actingAs($unrelatedOwner, 'web')
            ->post("/app/customers/{$customer->id}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertNotFound();

        $this->assertEquals(300, (float) $customer->fresh()->due); // untouched
    }
}
