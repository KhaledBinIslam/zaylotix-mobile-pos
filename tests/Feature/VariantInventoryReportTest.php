<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Reports::variantInventoryBreakdown() — a current stock snapshot grouped by
 * category and listed per color/size, so a clothing shop can see "কোন
 * category, color, size এ কতগুলো আছে" without opening every product.
 */
class VariantInventoryReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_variant_inventory_breakdown_groups_by_category_and_lists_each_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'reports');

        $shirts = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'শার্ট']);
        $pants = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'প্যান্ট']);

        $shirt = Product::create(['shop_id' => $shop->id, 'category_id' => $shirts->id, 'name' => 'Formal Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'color' => 'Blue', 'size' => 'M', 'stock' => 12]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'color' => 'Blue', 'size' => 'L', 'stock' => 8]);
        Product::whereKey($shirt->id)->update(['stock' => 20]);

        $pant = Product::create(['shop_id' => $shop->id, 'category_id' => $pants->id, 'name' => 'Jeans', 'cost' => 300, 'price' => 600, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $pant->id, 'color' => 'Black', 'size' => '32', 'stock' => 5]);
        Product::whereKey($pant->id)->update(['stock' => 5]);

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(function ($page) {
            $breakdown = $page->toArray()['props']['variantInventory'];
            $byCategory = collect($breakdown['byCategory'])->keyBy('category');

            $this->assertSame(20, $byCategory['শার্ট']['total_stock']);
            $this->assertSame(2, $byCategory['শার্ট']['variant_count']);
            $this->assertSame(5, $byCategory['প্যান্ট']['total_stock']);
            $this->assertCount(3, $breakdown['rows']);
        });
    }

    public function test_variant_inventory_is_null_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // no product_variants feature
        $this->grantFeature($shop, 'reports');

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('variantInventory', null));
    }
}
