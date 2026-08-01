<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Proves the feature-permission system is enforced server-side, not just
 * hidden in the UI — a shop without a capability granted gets a real 403,
 * and granting it (the same way the admin "create/edit shop" form does)
 * immediately unlocks the route without any code change.
 */
class FeatureGatingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_shop_without_the_feature_is_forbidden(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertForbidden();
    }

    public function test_shop_with_the_feature_granted_can_access_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk();
    }

    public function test_accounts_route_is_feature_gated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/app/accounts')->assertForbidden();

        $this->grantFeature($shop, 'accounts');
        $this->actingAs($owner, 'web')->get('/app/accounts')->assertOk();
    }

    public function test_cashier_management_route_is_feature_gated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'Cashier', 'phone' => '01900009999', 'password' => 'secret1', 'permissions' => ['pos'],
        ])->assertForbidden();

        $this->grantFeature($shop, 'cashier_management');
        $this->actingAs($owner, 'web')->post('/app/staff', [
            'name' => 'Cashier', 'phone' => '01900009999', 'password' => 'secret1', 'permissions' => ['pos'],
        ])->assertRedirect();
    }

    public function test_unit_conversion_routes_are_feature_gated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = \App\Models\Product::create(['shop_id' => $shop->id, 'name' => 'Test', 'cost' => 1, 'price' => 2, 'stock' => 10]);

        $this->actingAs($owner, 'web')
            ->post("/app/products/{$product->id}/units", ['factor' => 10, 'price' => 15, 'new_unit_name' => 'Box'])
            ->assertForbidden();

        $this->grantFeature($shop, 'unit_conversion');

        $this->actingAs($owner, 'web')
            ->post("/app/products/{$product->id}/units", ['factor' => 10, 'price' => 15, 'new_unit_name' => 'Box'])
            ->assertRedirect();

        $this->assertDatabaseHas('product_units', ['product_id' => $product->id, 'factor' => 10, 'price' => 15]);
    }
}
