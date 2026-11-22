<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ProductsUnlinkDemoPoolContaminationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_dry_run_exports_a_csv_and_changes_nothing(): void
    {
        [$main] = $this->createShopWithOwner();
        [$demo] = $this->createShopWithOwner(['account_type' => 'demo']);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $demoProduct = Product::create(['shop_id' => $demo->id, 'master_product_id' => $mainProduct->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 999]);

        $this->artisan('products:unlink-demo-pool-contamination')
            ->expectsOutputToContain('প্রি-ইমেজ এক্সপোর্ট')
            ->assertExitCode(0);

        $this->assertEquals($mainProduct->id, $demoProduct->fresh()->master_product_id);
    }

    public function test_apply_nulls_out_the_contaminated_link_only(): void
    {
        [$main] = $this->createShopWithOwner();
        [$demo] = $this->createShopWithOwner(['account_type' => 'demo']);
        $branch = (function () use ($main) {
            [$b] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

            return $b;
        })();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);
        $demoProduct = Product::create(['shop_id' => $demo->id, 'master_product_id' => $mainProduct->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 999]);
        // a genuine branch copy must survive untouched
        $branchProduct = Product::create(['shop_id' => $branch->id, 'master_product_id' => $mainProduct->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);

        $this->artisan('products:unlink-demo-pool-contamination', ['--apply' => true])->assertExitCode(0);

        $this->assertNull($demoProduct->fresh()->master_product_id);
        $this->assertEquals($mainProduct->id, $branchProduct->fresh()->master_product_id);
    }
}
