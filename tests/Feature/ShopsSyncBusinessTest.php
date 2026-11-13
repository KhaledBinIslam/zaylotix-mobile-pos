<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsSyncBusinessTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_syncs_a_branchs_missing_color_barcodes_from_either_shop_id(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $branch = Shop::create([
            'name' => 'লাইভারটি', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);

        $product = Product::create(['shop_id' => $main->id, 'name' => 'L-10003', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '38', 'color' => 'BLACK', 'stock' => 2]);
        ProductColorBarcode::create(['shop_id' => $main->id, 'product_id' => $product->id, 'color' => 'BLACK', 'barcode' => 'ANG-L-10003-BK']);

        // --shop can be the BRANCH id, same as a support call pointing at
        // whichever shop the owner actually mentioned
        $this->artisan('shops:sync-business', ['--shop' => $branch->id])->assertSuccessful();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $product->id)->firstOrFail();
        $this->assertSame(
            'ANG-L-10003-BK',
            ProductColorBarcode::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProduct->id)->value('barcode')
        );
    }

    public function test_reports_a_shop_with_no_branches(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('shops:sync-business', ['--shop' => $shop->id])->assertFailed();
    }
}
