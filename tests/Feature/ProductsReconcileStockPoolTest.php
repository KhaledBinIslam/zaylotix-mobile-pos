<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Repairs the exact live bug Khaled hit: a branch copy synced BEFORE
 * CatalogSync's starting-stock fix (or corrupted some other way) sitting
 * at the wrong stock despite shared_stock_pool being on. Simulates that
 * pre-fix state directly (force the branch's stock wrong after a normal
 * sync) rather than depending on CatalogSync's own behavior, so this test
 * stays meaningful even after that bug is long fixed.
 */
class ProductsReconcileStockPoolTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_corrects_a_mismatched_product_and_variant_to_the_main_shops_value(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'L-10001-SILVER-36', 'stock' => 5]);
        CatalogSync::syncToBranch($main, $branch);

        // simulate the pre-fix bug: branch copy sitting at 0 despite the pool
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProduct->id)->first();
        Product::withoutGlobalScopes()->whereKey($branchProduct->id)->update(['stock' => 0]);
        ProductVariant::withoutGlobalScopes()->whereKey($branchVariant->id)->update(['stock' => 0]);

        $this->artisan('products:reconcile-stock-pool', ['--shop' => $main->id])
            ->expectsOutputToContain('1টা আর্টিকেল, 1টা ভ্যারিয়েন্ট ঠিক করা হলো।')
            ->assertSuccessful();

        $this->assertEquals(11, (float) $branchProduct->fresh()->stock);
        $this->assertEquals(5, (float) $branchVariant->fresh()->stock);
        // the main shop's own values must never move
        $this->assertEquals(11, (float) $mainProduct->fresh()->stock);
        $this->assertEquals(5, (float) $mainVariant->fresh()->stock);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        CatalogSync::syncToBranch($main, $branch);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        Product::withoutGlobalScopes()->whereKey($branchProduct->id)->update(['stock' => 0]);

        $this->artisan('products:reconcile-stock-pool', ['--shop' => $main->id, '--dry-run' => true])
            ->expectsOutputToContain('প্রিভিউ')
            ->assertSuccessful();

        $this->assertEquals(0, (float) $branchProduct->fresh()->stock);
    }

    public function test_an_already_matching_product_is_left_untouched(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        CatalogSync::syncToBranch($main, $branch); // already starts matched, thanks to the fix

        $this->artisan('products:reconcile-stock-pool', ['--shop' => $main->id])
            ->expectsOutputToContain('0টা আর্টিকেল, 0টা ভ্যারিয়েন্ট ঠিক করা হলো।')
            ->assertSuccessful();
    }

    public function test_rejects_a_branch_shop_id(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $this->artisan('products:reconcile-stock-pool', ['--shop' => $branch->id])
            ->expectsOutputToContain('মূল শপের ID হতে হবে')
            ->assertFailed();
    }

    public function test_rejects_a_shop_without_the_pool_feature(): void
    {
        [$main] = $this->createShopWithOwner();

        $this->artisan('products:reconcile-stock-pool', ['--shop' => $main->id])
            ->expectsOutputToContain('shared_stock_pool ফিচার চালু নেই')
            ->assertFailed();
    }

    public function test_tenant_isolation_never_touches_another_chains_shops(): void
    {
        [$mainA] = $this->createShopWithOwner();
        $this->grantFeature($mainA, 'shared_catalog');
        $this->grantFeature($mainA, 'shared_stock_pool');
        $branchA = $this->createBranch($mainA);

        [$mainB] = $this->createShopWithOwner();
        $this->grantFeature($mainB, 'shared_catalog');
        $this->grantFeature($mainB, 'shared_stock_pool');
        $branchB = $this->createBranch($mainB);

        $productA = Product::create(['shop_id' => $mainA->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 7]);
        CatalogSync::syncToBranch($mainA, $branchA);
        $branchAProduct = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $productA->id)->first();
        Product::withoutGlobalScopes()->whereKey($branchAProduct->id)->update(['stock' => 0]);

        $productB = Product::create(['shop_id' => $mainB->id, 'name' => 'B', 'cost' => 10, 'price' => 20, 'stock' => 9]);
        CatalogSync::syncToBranch($mainB, $branchB);
        $branchBProduct = Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('master_product_id', $productB->id)->first();
        Product::withoutGlobalScopes()->whereKey($branchBProduct->id)->update(['stock' => 0]);

        $this->artisan('products:reconcile-stock-pool', ['--shop' => $mainA->id])->assertSuccessful();

        $this->assertEquals(7, (float) $branchAProduct->fresh()->stock);
        $this->assertEquals(0, (float) $branchBProduct->fresh()->stock); // untouched -- different chain
    }
}
