<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Khaled: "jutar unit pcs na, jora (pair)" — backfills unit_id for articles products:import-articles created before its own unit-resolution fix. */
class ProductsSetUnitTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_sets_the_unit_on_every_product_with_no_unit_yet(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);

        $this->artisan('products:set-unit', ['--shop' => $shop->id, '--name' => 'জোড়া', '--name-en' => 'Pair'])
            ->expectsOutputToContain('1টা আর্টিকেলের')
            ->assertSuccessful();

        $unit = Unit::withoutGlobalScopes()->find($product->fresh()->unit_id);
        $this->assertNotNull($unit);
        $this->assertSame('জোড়া', $unit->name);
        $this->assertSame('Pair', $unit->name_en);
    }

    public function test_a_product_that_already_has_a_unit_is_left_untouched(): void
    {
        [$shop] = $this->createShopWithOwner();
        $existingUnit = Unit::create(['shop_id' => $shop->id, 'name' => 'পিস', 'name_en' => 'Pcs']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5, 'unit_id' => $existingUnit->id]);

        $this->artisan('products:set-unit', ['--shop' => $shop->id, '--name' => 'জোড়া'])->assertSuccessful();

        $this->assertSame($existingUnit->id, $product->fresh()->unit_id);
    }

    public function test_reuses_an_existing_unit_of_the_same_name_instead_of_duplicating(): void
    {
        [$shop] = $this->createShopWithOwner();
        $existingUnit = Unit::create(['shop_id' => $shop->id, 'name' => 'জোড়া', 'name_en' => 'Pair']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);

        $this->artisan('products:set-unit', ['--shop' => $shop->id, '--name' => 'জোড়া'])->assertSuccessful();

        $this->assertSame(1, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'জোড়া')->count());
        $this->assertSame($existingUnit->id, $product->fresh()->unit_id);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);

        $this->artisan('products:set-unit', ['--shop' => $shop->id, '--name' => 'জোড়া', '--dry-run' => true])
            ->expectsOutputToContain('প্রিভিউ')
            ->assertSuccessful();

        $this->assertNull($product->fresh()->unit_id);
        $this->assertSame(0, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_propagates_to_branches_when_shared_catalog_is_on(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        CatalogSync::syncToBranch($main, $branch);

        $this->artisan('products:set-unit', ['--shop' => $main->id, '--name' => 'জোড়া', '--name-en' => 'Pair'])->assertSuccessful();

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->first();
        $branchUnit = Unit::withoutGlobalScopes()->find($branchProduct->unit_id);
        $this->assertNotNull($branchUnit);
        $this->assertSame('জোড়া', $branchUnit->name);
    }

    public function test_rejects_a_missing_name(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('products:set-unit', ['--shop' => $shop->id])
            ->expectsOutputToContain('--name')
            ->assertFailed();
    }
}
