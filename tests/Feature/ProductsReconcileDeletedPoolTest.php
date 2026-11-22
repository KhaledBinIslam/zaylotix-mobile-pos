<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The one-time data-fix command for the pre-existing asymmetric-delete
 * bug (Product::deleteBranchCopies() used to only cascade master ->
 * branches) -- real affected rows (Baby Park, shop 80/81) are fixed
 * separately in production once Khaled confirms; this proves the
 * command's own logic is correct against a synthetic pool first.
 */
class ProductsReconcileDeletedPoolTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_dry_run_lists_a_mismatched_group_without_changing_anything(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'B-20025', 'cost' => 1450, 'price' => 2000, 'stock' => 1050]);
        $branchProduct = Product::create(['shop_id' => $branch->id, 'master_product_id' => $mainProduct->id, 'name' => 'B-20025', 'cost' => 1450, 'price' => 2000, 'stock' => 1050]);

        // simulate the pre-fix bug directly: delete the branch copy via a
        // raw query-builder update, bypassing the model event entirely --
        // exactly the stuck state the real incident left behind
        Product::withoutGlobalScopes()->whereKey($branchProduct->id)->update(['deleted_at' => now()]);

        $this->artisan('products:reconcile-deleted-pool', ['--shop' => $main->id])
            ->expectsOutputToContain('mismatch')
            ->assertExitCode(0);

        // dry-run: master must still be untouched (active)
        $this->assertFalse($mainProduct->fresh()->trashed());
    }

    public function test_apply_deletes_the_still_active_side_to_match(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'B-20025', 'cost' => 1450, 'price' => 2000, 'stock' => 1050]);
        $branchProduct = Product::create(['shop_id' => $branch->id, 'master_product_id' => $mainProduct->id, 'name' => 'B-20025', 'cost' => 1450, 'price' => 2000, 'stock' => 1050]);

        Product::withoutGlobalScopes()->whereKey($branchProduct->id)->update(['deleted_at' => now()]);

        $this->artisan('products:reconcile-deleted-pool', ['--shop' => $main->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertTrue($mainProduct->fresh()->trashed());
        $this->assertTrue(Product::withoutGlobalScopes()->whereKey($branchProduct->id)->first()->trashed());
    }

    public function test_an_agreeing_group_is_never_touched(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_stock_pool');
        $branch = $this->createBranch($main);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        Product::create(['shop_id' => $branch->id, 'master_product_id' => $mainProduct->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);

        $this->artisan('products:reconcile-deleted-pool', ['--shop' => $main->id, '--apply' => true])
            ->expectsOutputToContain('0টা পণ্য-গ্রুপে')
            ->assertExitCode(0);

        $this->assertFalse($mainProduct->fresh()->trashed());
    }
}
