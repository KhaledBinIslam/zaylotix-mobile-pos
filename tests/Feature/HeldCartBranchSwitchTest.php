<?php

namespace Tests\Feature;

use App\Models\HeldCart;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park, 2026-11-24: the held-carts sheet listed the branch's carts but
 * Delete answered "অনুমতি নেই" (403). The owner works in a branch they
 * switched into; the policy compared the cart's shop with the owner's HOME
 * shop. A row of the branch you are working in must be yours to delete —
 * and a row of any OTHER business still must not.
 */
class HeldCartBranchSwitchTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function ownerInBranch(): array
    {
        [$main, $owner] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);

        return [$main, $branch, $owner];
    }

    public function test_an_owner_switched_into_a_branch_can_delete_that_branchs_held_cart(): void
    {
        [$main, $branch, $owner] = $this->ownerInBranch();
        $cart = HeldCart::withoutGlobalScopes()->create(['shop_id' => $branch->id, 'label' => 'Held #41', 'cart_data' => []]);

        $this->actingAs($owner, 'web')->withSession(['active_branch_id' => $branch->id])
            ->delete(route('app.heldCarts.destroy', $cart->id))
            ->assertSessionHasNoErrors()->assertStatus(302);

        $this->assertDatabaseMissing('held_carts', ['id' => $cart->id]);
    }

    public function test_the_owner_can_still_delete_their_own_shops_held_cart_without_switching(): void
    {
        [$main, , $owner] = $this->ownerInBranch();
        $cart = HeldCart::withoutGlobalScopes()->create(['shop_id' => $main->id, 'label' => 'Held #1', 'cart_data' => []]);

        $this->actingAs($owner, 'web')->delete(route('app.heldCarts.destroy', $cart->id))->assertStatus(302);

        $this->assertDatabaseMissing('held_carts', ['id' => $cart->id]);
    }

    public function test_another_businesss_held_cart_is_still_untouchable_even_while_switched(): void
    {
        [, $branch, $owner] = $this->ownerInBranch();
        [$otherShop] = $this->createShopWithOwner();
        $foreign = HeldCart::withoutGlobalScopes()->create(['shop_id' => $otherShop->id, 'label' => 'Not yours', 'cart_data' => []]);

        $this->actingAs($owner, 'web')->withSession(['active_branch_id' => $branch->id])
            ->delete(route('app.heldCarts.destroy', $foreign->id));

        $this->assertDatabaseHas('held_carts', ['id' => $foreign->id]);
    }
}
