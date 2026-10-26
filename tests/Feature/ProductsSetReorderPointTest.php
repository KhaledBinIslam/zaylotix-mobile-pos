<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Khaled: "3 pair or er kom thakle stock alert asbe" — backfills reorder_point for articles the import/entry flow never asked for one. */
class ProductsSetReorderPointTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_sets_reorder_point_on_every_product_and_variant_of_the_shop(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 2]);

        $this->artisan('products:set-reorder-point', ['--shop' => $shop->id, '--value' => 3])
            ->expectsOutputToContain('1টা আর্টিকেল')
            ->assertSuccessful();

        $this->assertEquals(3, $product->fresh()->reorder_point);
        $this->assertEquals(3, $variant->fresh()->reorder_point);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);

        $this->artisan('products:set-reorder-point', ['--shop' => $shop->id, '--value' => 3, '--dry-run' => true])
            ->expectsOutputToContain('প্রিভিউ')
            ->assertSuccessful();

        $this->assertNull($product->fresh()->reorder_point);
    }

    public function test_propagates_to_branches_when_shared_catalog_is_on(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'L-10001-SILVER-36', 'stock' => 2]);
        CatalogSync::syncToBranch($main, $branch);

        $this->artisan('products:set-reorder-point', ['--shop' => $main->id, '--value' => 3])->assertSuccessful();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProduct->id)->first();
        $this->assertEquals(3, $branchProduct->reorder_point);
        $this->assertEquals(3, $branchVariant->reorder_point);
    }

    public function test_rejects_a_missing_value(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('products:set-reorder-point', ['--shop' => $shop->id])
            ->expectsOutputToContain('--value')
            ->assertFailed();
    }
}
