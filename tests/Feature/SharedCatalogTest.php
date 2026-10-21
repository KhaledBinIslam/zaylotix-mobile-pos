<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 4 item #1, part A — "শেয়ার্ড ক্যাটালগ" real-time sync (see
 * docs/FIX_PLAN.md's design note). CatalogSync's manual full-catalog sync
 * already existed and is untouched by this; what's new is: (1) real-time
 * propagation of a single product save, gated on the opt-in shared_catalog
 * feature, and (2) the master_product_id link that makes matching durable
 * across a rename.
 *
 * Every cross-shop assertion below reads via Product::withoutGlobalScopes()
 * — Product is BelongsToTenant, so a plain query only ever sees the shop
 * Tenancy::id() currently resolves to (the main shop, for the rest of a test
 * after actingAs($mainOwner) — never the branch whose row we actually want
 * to inspect).
 */
class SharedCatalogTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_saving_a_product_on_the_main_shop_propagates_to_every_branch_in_real_time(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);

        $this->actingAs($owner, 'web')->post(route('app.products.store'), [
            'name' => 'Coca Cola 500ml', 'cost' => 30, 'price' => 50, 'stock' => 20,
        ]);

        $mainProduct = Product::where('name', 'Coca Cola 500ml')->first();
        $this->assertNotNull($mainProduct);

        $branchAProduct = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $mainProduct->id)->first();
        $branchBProduct = Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('master_product_id', $mainProduct->id)->first();
        $this->assertNotNull($branchAProduct);
        $this->assertNotNull($branchBProduct);
        $this->assertEquals(50, (float) $branchAProduct->price);
        // stock is never pushed -- each branch starts its own linked copy at 0, to be physically counted in
        $this->assertEquals(0, (float) $branchAProduct->stock);
    }

    public function test_editing_the_main_shops_product_updates_every_linked_branch_copy(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $branch = $this->createBranch($main);
        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Coca Cola 500ml', 'cost' => 30, 'price' => 50, 'stock' => 20]);
        CatalogSync::syncProductToBranches($main, $mainProduct);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $this->assertNotNull($branchProduct);

        $this->actingAs($owner, 'web')->put(route('app.products.update', $mainProduct->id), [
            'name' => 'Coca Cola 500ml', 'cost' => 30, 'price' => 60, 'stock' => 20,
        ]);

        $this->assertEquals(60, (float) $branchProduct->refresh()->price);
    }

    public function test_a_branch_cannot_change_a_linked_products_shared_fields_locally(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();
        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Coca Cola 500ml', 'cost' => 30, 'price' => 50, 'stock' => 20]);
        CatalogSync::syncProductToBranches($main, $mainProduct);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $this->assertNotNull($branchProduct);

        $this->actingAs($branchOwner, 'web')->put(route('app.products.update', $branchProduct->id), [
            'name' => 'Renamed Locally', 'cost' => 30, 'price' => 999, 'stock' => 15,
        ]);

        $branchProduct->refresh();
        // the shared fields never changed...
        $this->assertSame('Coca Cola 500ml', $branchProduct->name);
        $this->assertEquals(50, (float) $branchProduct->price);
        // ...but stock, always branch-local, did
        $this->assertEquals(15, (float) $branchProduct->stock);
    }

    public function test_matching_survives_a_rename_once_linked_by_master_product_id(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $branch = $this->createBranch($main);
        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Coca Cola 500ml', 'cost' => 30, 'price' => 50, 'stock' => 20]);
        CatalogSync::syncProductToBranches($main, $mainProduct);

        // renaming on the main shop (no barcode set, so a pure name-match would break) must still hit the SAME branch row
        $this->actingAs($owner, 'web')->put(route('app.products.update', $mainProduct->id), [
            'name' => 'Coca Cola 500ml (New Label)', 'cost' => 30, 'price' => 50, 'stock' => 20,
        ]);

        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $branch->id)->count());
        $this->assertSame('Coca Cola 500ml (New Label)', Product::withoutGlobalScopes()->where('shop_id', $branch->id)->first()->name);
    }

    public function test_no_propagation_without_the_feature_granted(): void
    {
        [$main, $owner] = $this->createShopWithOwner(); // feature not granted
        $branch = $this->createBranch($main);

        $this->actingAs($owner, 'web')->post(route('app.products.store'), [
            'name' => 'Coca Cola 500ml', 'cost' => 30, 'price' => 50, 'stock' => 20,
        ]);

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $branch->id)->count());
    }

    public function test_a_branchs_own_locally_created_product_never_propagates_anywhere(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog'); // granted to the main shop, not the branch
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();
        $siblingBranch = $this->createBranch($main);

        $this->actingAs($branchOwner, 'web')->post(route('app.products.store'), [
            'name' => 'Branch-only snack', 'cost' => 10, 'price' => 20, 'stock' => 5,
        ]);

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $siblingBranch->id)->count());
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $main->id)->count());
    }

    public function test_a_single_shop_business_sees_no_change_at_all(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // no branches, feature not even relevant

        $response = $this->actingAs($owner, 'web')->post(route('app.products.store'), [
            'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Product::count());
        $this->assertNull(Product::first()->master_product_id);
    }
}
