<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Online Business module, Part 8 (optional, independent of online_business
 * itself) — see docs/FIX_PLAN.md's Phase 38 design note. Every stock
 * mutation in the app now routes through Product::applyStockDelta() /
 * ProductVariant::applyStockDelta() instead of a raw increment()/decrement()/
 * update(); this is a no-op for every shop that doesn't grant the opt-in
 * shared_stock_pool feature to its MAIN shop (same convention as
 * shared_catalog) — that's the regression guard the first test here is for.
 *
 * Every cross-shop assertion reads via Product::withoutGlobalScopes() —
 * Product is BelongsToTenant, so a plain query only ever sees whichever shop
 * Tenancy::id() currently resolves to.
 */
class SharedStockPoolTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    /** Links $branch's copy of $mainProduct and forces its stock to a chosen starting value, regardless of whatever CatalogSync itself would have started it at. */
    private function linkBranch(Shop $main, Shop $branch, Product $mainProduct, float $startingStock): Product
    {
        CatalogSync::syncProductToBranches($main, $mainProduct);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        Product::withoutGlobalScopes()->whereKey($branchProduct->id)->update(['stock' => $startingStock]);

        return $branchProduct->refresh();
    }

    private function sell(Shop $shop, $owner, Product $product, float $qty): void
    {
        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => (float) $product->price * $qty]],
        ]);
        $response->assertOk();
    }

    public function test_without_the_feature_every_branch_keeps_its_own_independent_stock(): void
    {
        [$main] = $this->createShopWithOwner(); // feature not granted
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $branchAOwner = $branchA->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $branchAProduct = $this->linkBranch($main, $branchA, $mainProduct, 50);
        $this->linkBranch($main, $branchB, $mainProduct, 30);

        $this->sell($branchA, $branchAOwner, $branchAProduct, 5);

        $this->assertEquals(45, (float) $branchAProduct->refresh()->stock);
        $this->assertEquals(30, (float) Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->first()->stock);
        $this->assertEquals(100, (float) $mainProduct->refresh()->stock);
    }

    public function test_granting_the_feature_to_the_main_shop_pools_stock_across_every_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $branchAOwner = $branchA->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $branchAProduct = $this->linkBranch($main, $branchA, $mainProduct, 100);
        $branchBProduct = $this->linkBranch($main, $branchB, $mainProduct, 100);

        $this->sell($branchA, $branchAOwner, $branchAProduct, 5);

        $this->assertEquals(95, (float) $branchAProduct->refresh()->stock);
        $this->assertEquals(95, (float) $branchBProduct->refresh()->stock);
        $this->assertEquals(95, (float) $mainProduct->refresh()->stock);
    }

    /**
     * Real bug Khaled hit live: products:import-articles creates the main
     * shop's product with real stock, syncs it to the branch via
     * CatalogSync — and before this fix, the branch's freshly-created copy
     * sat at 0 (CatalogSync's normal "to be physically counted in" default)
     * even though the two are supposed to share ONE pooled number, meaning
     * the branch's POS couldn't sell it until some other stock event
     * happened to self-correct it. A brand-new pooled row must start
     * already equal to the main shop's current count, not an artificial 0.
     */
    public function test_a_freshly_synced_branch_copy_starts_with_the_pooled_stock_not_zero(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => 'L', 'barcode' => 'RICE-L', 'stock' => 20]);

        CatalogSync::syncToBranch($main, $branch);

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProduct->id)->first();

        $this->assertEquals(100, (float) $branchProduct->stock);
        $this->assertEquals(20, (float) $branchVariant->stock);
    }

    /** Without shared_stock_pool, a freshly-synced branch copy still starts at 0 (unchanged, pre-existing behavior) -- stock pooling must be opted into, not inferred from shared_catalog alone. */
    public function test_a_freshly_synced_branch_copy_without_the_pool_feature_still_starts_at_zero(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog'); // shared_stock_pool deliberately NOT granted
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);

        CatalogSync::syncToBranch($main, $branch);

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $this->assertEquals(0, (float) $branchProduct->stock);
    }

    public function test_granting_the_feature_to_a_branch_instead_of_the_main_shop_does_nothing(): void
    {
        [$main] = $this->createShopWithOwner();
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $this->grantFeature($branchA, 'shared_stock_pool'); // granted to the wrong shop on purpose
        $branchAOwner = $branchA->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $branchAProduct = $this->linkBranch($main, $branchA, $mainProduct, 100);
        $branchBProduct = $this->linkBranch($main, $branchB, $mainProduct, 100);

        $this->sell($branchA, $branchAOwner, $branchAProduct, 5);

        $this->assertEquals(95, (float) $branchAProduct->refresh()->stock);
        $this->assertEquals(100, (float) $branchBProduct->refresh()->stock);
        $this->assertEquals(100, (float) $mainProduct->refresh()->stock);
    }

    public function test_stock_in_at_one_branch_raises_the_pool_everywhere(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $branchBOwner = $branchB->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $branchAProduct = $this->linkBranch($main, $branchA, $mainProduct, 100);
        $branchBProduct = $this->linkBranch($main, $branchB, $mainProduct, 100);

        $response = $this->actingAs($branchBOwner, 'web')->post(route('app.products.stockIn', $branchBProduct->id), ['qty' => 20]);
        $response->assertSessionHasNoErrors();

        $this->assertEquals(120, (float) $branchBProduct->refresh()->stock);
        $this->assertEquals(120, (float) $branchAProduct->refresh()->stock);
        $this->assertEquals(120, (float) $mainProduct->refresh()->stock);
    }

    public function test_variant_stock_pools_across_branches_matched_by_barcode(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => 'L', 'barcode' => 'SHIRT-L', 'stock' => 20]);

        // real-time syncProductToBranches() is deliberately product-only
        // (see CatalogSync's own docblock) — a variant needs the full sync
        CatalogSync::syncToBranch($main, $branch);

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProduct->id)->where('barcode', 'SHIRT-L')->first();
        $this->assertNotNull($branchVariant);

        // force both sides to the same pooled starting value, same as every other test here
        Product::withoutGlobalScopes()->whereKey($mainProduct->id)->update(['stock' => 20]);
        Product::withoutGlobalScopes()->whereKey($branchProduct->id)->update(['stock' => 20]);
        ProductVariant::withoutGlobalScopes()->whereKey($branchVariant->id)->update(['stock' => 20]);

        $response = $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $branchProduct->id, 'product_variant_id' => $branchVariant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 400 * 3]],
        ]);
        $response->assertOk();

        $this->assertEquals(17, (float) $branchVariant->refresh()->stock);
        $this->assertEquals(17, (float) $mainVariant->refresh()->stock);
        $this->assertEquals(17, (float) $branchProduct->refresh()->stock);
        $this->assertEquals(17, (float) $mainProduct->refresh()->stock);
    }

    public function test_transfer_between_two_pooled_siblings_nets_to_zero(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $branchAOwner = $branchA->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $branchAProduct = $this->linkBranch($main, $branchA, $mainProduct, 100);
        $this->linkBranch($main, $branchB, $mainProduct, 100);

        // product_id is validated against the ACTING shop (the transfer's
        // source) — see StockTransferController::store()'s own validation
        $response = $this->actingAs($branchAOwner, 'web')->post(route('app.stockTransfers.store'), [
            'to_shop_id' => $branchB->id,
            'product_id' => $branchAProduct->id,
            'lines' => [['qty' => 10]],
        ]);
        $response->assertSessionHasNoErrors();

        // the "destination" row (auto-created or matched in branch B) now
        // shares the exact same pool as the source — moving shared stock
        // between two branches that already share it is correctly a no-op
        $this->assertEquals(100, (float) $mainProduct->refresh()->stock);
        $this->assertEquals(100, (float) $branchAProduct->refresh()->stock);
    }

    /**
     * See docs/SCALING_GUIDE.md item #1 — applyStockDelta() runs on EVERY
     * stock mutation in the app, so a shop that never touches this feature
     * must pay nothing extra for it. Locks in the actual query count, not
     * just correct behavior, same pattern as TenancyScaleCacheTest.
     */
    public function test_a_shop_without_the_feature_pays_zero_extra_queries_for_pooling_checks(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted, no branches at all
        $this->actingAs($owner, 'web');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 50]);

        // warm Tenancy's own per-request shop/feature caches first (see
        // TenancyScaleCacheTest) — that one-time cost is unrelated to
        // applyStockDelta() and every other feature-gated route already
        // pays it regardless of this feature existing at all.
        \App\Support\Tenancy::shop()->hasFeature('shared_stock_pool');

        DB::enableQueryLog();
        $product->applyStockDelta(-5);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals(45, (float) $product->stock);
        // exactly the one plain increment — no pool-membership lookup query
        $this->assertCount(1, $queries, 'applyStockDelta() ran extra queries for a shop that never granted shared_stock_pool');
    }

    public function test_a_branchs_own_unlinked_product_is_never_pooled_even_with_the_feature_on(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $branchAOwner = $branchA->users()->first();

        // created locally on branch A, never synced from the main shop —
        // no master_product_id, so never a pool member
        $branchAProduct = Product::create(['shop_id' => $branchA->id, 'name' => 'Local snack', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        $siblingProduct = Product::create(['shop_id' => $branchB->id, 'name' => 'Unrelated', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $this->sell($branchA, $branchAOwner, $branchAProduct, 5);

        $this->assertEquals(45, (float) $branchAProduct->refresh()->stock);
        $this->assertEquals(50, (float) $siblingProduct->refresh()->stock);
    }
}
