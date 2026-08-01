<?php

namespace Tests\Feature;

use App\Models\HeldCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A held cart is a parked, not-yet-checked-out sale — pure client-side cart
 * state persisted server-side so a cashier can serve another customer and
 * come back to it. Checkout itself remains the only place that validates
 * stock/prices, so holding/resuming has no business-rule checks of its own.
 */
class HeldCartTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_hold_a_cart(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/pos/held-carts', [
            'cart' => [['product_id' => 1, 'product_unit_id' => null, 'product_variant_id' => null, 'qty' => 2, 'discount' => 0]],
            'discount' => 10,
            'customer_phone' => '01711111111',
            'customer_name' => 'Karim',
        ])->assertRedirect();

        $this->assertSame(1, HeldCart::count());
        $held = HeldCart::first();
        $this->assertSame(10.0, (float) $held->cart_data['discount']);
        $this->assertSame('01711111111', $held->cart_data['customer_phone']);
    }

    public function test_resuming_returns_the_data_and_removes_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $held = HeldCart::create(['shop_id' => $shop->id, 'cart_data' => ['cart' => [['product_id' => 1, 'qty' => 3]], 'discount' => 5, 'customer_phone' => '', 'customer_name' => '']]);

        $response = $this->actingAs($owner, 'web')->postJson("/app/pos/held-carts/{$held->id}/resume");

        $response->assertOk()->assertJson(['discount' => 5]);
        $this->assertSame(0, HeldCart::count()); // resumed carts are removed, not left duplicated
    }

    public function test_owner_can_delete_a_held_cart_without_resuming(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $held = HeldCart::create(['shop_id' => $shop->id, 'cart_data' => ['cart' => [], 'discount' => 0, 'customer_phone' => '', 'customer_name' => '']]);

        $this->actingAs($owner, 'web')->delete("/app/pos/held-carts/{$held->id}")->assertRedirect();

        $this->assertSame(0, HeldCart::count());
    }

    public function test_a_shops_held_carts_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $heldB = HeldCart::create(['shop_id' => $shopB->id, 'cart_data' => ['cart' => [], 'discount' => 0, 'customer_phone' => '', 'customer_name' => '']]);

        $this->actingAs($ownerA, 'web')->postJson("/app/pos/held-carts/{$heldB->id}/resume")->assertNotFound();
    }
}
