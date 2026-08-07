<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The owner-to-cashier permission layer: a shop owner can add cashiers (up
 * to the shop's staff_limit — see StaffController::DEFAULT_STAFF_CAP) and
 * decide which app sections each can reach. This sits inside (and is
 * independent of) the admin-to-shop `feature` layer — a cashier without a
 * grant gets a real 403 on the route, not just a hidden button.
 */
class StaffPermissionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_create_a_cashier_with_specific_permissions(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'cashier_management');

        $response = $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'Cashier One',
            'phone' => '01900001111',
            'password' => 'secret1',
            'permissions' => ['pos', 'stock'],
        ]);

        $response->assertRedirect();

        $cashier = User::where('shop_id', $shop->id)->where('role', 'staff')->first();
        $this->assertNotNull($cashier);
        $this->assertSame(['pos', 'stock'], $cashier->permissions);
    }

    public function test_a_shop_can_have_more_than_one_cashier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'cashier_management');

        $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'First', 'phone' => '01900001111', 'password' => 'secret1', 'permissions' => ['pos'],
        ])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'Second', 'phone' => '01900002222', 'password' => 'secret1', 'permissions' => ['pos'],
        ]);

        $response->assertRedirect();
        $this->assertSame(2, User::where('shop_id', $shop->id)->where('role', 'staff')->count());
    }

    public function test_the_staff_list_page_shows_every_cashier_for_the_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'cashier_management');
        User::create(['shop_id' => $shop->id, 'name' => 'Cashier A', 'phone' => '01900008888', 'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);
        User::create(['shop_id' => $shop->id, 'name' => 'Cashier B', 'phone' => '01900009999', 'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);

        $response = $this->actingAs($owner, 'web')->get('/app/staff');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Staff/Index')
            ->has('cashiers', 2)
        );
    }

    public function test_cashier_without_pos_permission_cannot_reach_pos(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900003333',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => ['stock'], 'lang' => 'bn',
        ]);

        $this->actingAs($cashier, 'web')->get('/app/pos')->assertForbidden();
        $this->actingAs($cashier, 'web')->get('/app/stock')->assertOk();
    }

    public function test_cashier_granted_pos_can_reach_it_and_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900004444',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => ['pos'], 'lang' => 'bn',
        ]);
        $product = \App\Models\Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 1, 'price' => 2, 'stock' => 10]);

        $this->actingAs($cashier, 'web')->get('/app/pos')->assertOk();

        $response = $this->actingAs($cashier, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 2]],
        ]);
        $response->assertOk();
    }

    public function test_cashier_cannot_manage_other_staff_even_with_settings_permission(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'cashier_management');
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900005555',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => ['settings'], 'lang' => 'bn',
        ]);

        $response = $this->actingAs($cashier, 'web')->post('/app/staff', [
            'name' => 'New Cashier', 'phone' => '01900006666', 'password' => 'secret1', 'permissions' => ['pos'],
        ]);

        $response->assertForbidden();
    }

    public function test_creating_a_cashier_beyond_the_default_cap_is_blocked(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // staff_limit null -> DEFAULT_STAFF_CAP (2)
        $this->grantFeature($shop, 'cashier_management');
        User::create(['shop_id' => $shop->id, 'name' => 'A', 'phone' => '01900011111', 'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);
        User::create(['shop_id' => $shop->id, 'name' => 'B', 'phone' => '01900022222', 'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);

        $response = $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'Third', 'phone' => '01900033333', 'password' => 'secret1', 'permissions' => ['pos'],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(2, User::where('shop_id', $shop->id)->where('role', 'staff')->count());
    }

    public function test_admin_raised_staff_limit_allows_more_cashiers_than_the_default(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['staff_limit' => 10]);
        $this->grantFeature($shop, 'cashier_management');
        User::create(['shop_id' => $shop->id, 'name' => 'A', 'phone' => '01900044444', 'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);
        User::create(['shop_id' => $shop->id, 'name' => 'B', 'phone' => '01900055555', 'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn']);

        $response = $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'Third', 'phone' => '01900066666', 'password' => 'secret1', 'permissions' => ['pos'],
        ]);

        $response->assertRedirect();
        $this->assertSame(3, User::where('shop_id', $shop->id)->where('role', 'staff')->count());
    }

    public function test_owner_cannot_edit_another_shops_cashier(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'cashier_management');
        [$shopB] = $this->createShopWithOwner();
        $cashierB = User::create([
            'shop_id' => $shopB->id, 'name' => 'B Cashier', 'phone' => '01900007777',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn',
        ]);

        $response = $this->actingAs($ownerA, 'web')->put("/app/staff/{$cashierB->id}", [
            'name' => 'Hacked', 'phone' => '01900007777', 'permissions' => ['accounts'],
        ]);

        $response->assertStatus(404);
        $this->assertSame('B Cashier', $cashierB->fresh()->name);
    }
}
