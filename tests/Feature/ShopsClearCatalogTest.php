<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsClearCatalogTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_wipes_the_catalog_but_keeps_the_shop_and_owner(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B']);
        $unit = Unit::create(['shop_id' => $shop->id, 'name' => 'Pair', 'name_en' => 'Pair', 'code' => 'pr']);
        $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'B-20001', 'name_en' => 'B-20001', 'barcode' => 'JUMP-B-20001-NSXXZ', 'cost' => 500, 'price' => 800, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '38', 'color' => 'WHITE', 'stock' => 0]);
        ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'WHITE', 'barcode' => 'JUMP-B-20001-NSXXZ-WHT']);

        $this->artisan('shops:clear-catalog', ['--shop' => $shop->id])->assertSuccessful();

        $this->assertSame(0, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->count());
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->withTrashed()->count());
        $this->assertSame(0, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());

        $this->assertNotNull($shop->fresh());
        $this->assertNotNull($owner->fresh());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner();
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B']);

        $this->artisan('shops:clear-catalog', ['--shop' => $shop->id, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_requires_a_shop_id(): void
    {
        $this->artisan('shops:clear-catalog')->assertFailed();
    }

    public function test_reports_an_unknown_shop_not_errors_silently(): void
    {
        $this->artisan('shops:clear-catalog', ['--shop' => 999999])->assertFailed();
    }
}
