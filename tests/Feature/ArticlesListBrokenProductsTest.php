<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ArticlesListBrokenProductsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_lists_a_product_whose_own_name_is_still_unprintable_with_its_id(): void
    {
        [$shop] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'School', 'name_en' => 'School', 'code' => 'S']);
        $broken = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'টেপবড়-70001', 'name_en' => 'টেপবড়-70001', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'S-70002', 'name_en' => 'S-70002', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->artisan('articles:list-broken-products', ['--shop' => $shop->id])
            ->expectsOutputToContain((string) $broken->id)
            ->assertSuccessful();
    }

    public function test_a_shop_with_nothing_broken_is_reported_cleanly(): void
    {
        [$shop] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'School', 'name_en' => 'School', 'code' => 'S']);
        Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'S-70002', 'name_en' => 'S-70002', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->artisan('articles:list-broken-products', ['--shop' => $shop->id])->assertSuccessful();
    }

    public function test_requires_shop_or_name(): void
    {
        $this->artisan('articles:list-broken-products')->assertFailed();
    }
}
