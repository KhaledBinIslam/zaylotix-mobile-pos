<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsDiffVariantBarcodesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_reports_a_barcode_mismatch_between_main_and_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Branch', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);

        $mp = Product::create(['shop_id' => $main->id, 'name' => 'L-10003', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mp->id, 'size' => '38', 'color' => 'BLACK', 'barcode' => 'MAIN-BC', 'stock' => 2]);
        $bp = Product::create(['shop_id' => $branch->id, 'name' => 'L-10003', 'master_product_id' => $mp->id, 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $bp->id, 'size' => '38', 'color' => 'BLACK', 'barcode' => 'STALE-BC', 'stock' => 2]);

        $this->artisan('shops:diff-variant-barcodes', ['--main' => $main->id, '--branch' => $branch->id])
            ->expectsOutputToContain('BARCODE MISMATCH L-10003 38/BLACK main=MAIN-BC branch=STALE-BC')
            ->expectsOutputToContain('checked=1 mismatches=1')
            ->assertSuccessful();
    }

    public function test_reports_a_stock_mismatch_between_main_and_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Branch', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);

        $mp = Product::create(['shop_id' => $main->id, 'name' => 'L-10003', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mp->id, 'size' => '38', 'color' => 'BLACK', 'barcode' => 'SAME-BC', 'stock' => 5]);
        $bp = Product::create(['shop_id' => $branch->id, 'name' => 'L-10003', 'master_product_id' => $mp->id, 'cost' => 100, 'price' => 200, 'stock' => 3]);
        ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $bp->id, 'size' => '38', 'color' => 'BLACK', 'barcode' => 'SAME-BC', 'stock' => 3]);

        $this->artisan('shops:diff-variant-barcodes', ['--main' => $main->id, '--branch' => $branch->id])
            ->expectsOutputToContain('STOCK MISMATCH L-10003 38/BLACK main_stock=5 branch_stock=3')
            ->expectsOutputToContain('PRODUCT STOCK MISMATCH L-10003 main_stock=5.000 branch_stock=3.000')
            ->assertSuccessful();
    }

    public function test_reports_zero_mismatches_when_everything_agrees(): void
    {
        [$main] = $this->createShopWithOwner();
        $branch = Shop::create([
            'name' => 'Branch', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);

        $mp = Product::create(['shop_id' => $main->id, 'name' => 'L-10003', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mp->id, 'size' => '38', 'color' => 'BLACK', 'barcode' => 'SAME-BC', 'stock' => 2]);
        $bp = Product::create(['shop_id' => $branch->id, 'name' => 'L-10003', 'master_product_id' => $mp->id, 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $branch->id, 'product_id' => $bp->id, 'size' => '38', 'color' => 'BLACK', 'barcode' => 'SAME-BC', 'stock' => 2]);

        $this->artisan('shops:diff-variant-barcodes', ['--main' => $main->id, '--branch' => $branch->id])
            ->expectsOutputToContain('checked=1 mismatches=0')
            ->assertSuccessful();
    }
}
