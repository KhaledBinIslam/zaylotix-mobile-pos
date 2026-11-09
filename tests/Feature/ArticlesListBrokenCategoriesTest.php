<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ArticlesListBrokenCategoriesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_lists_a_category_whose_code_is_still_unprintable_with_its_id(): void
    {
        [$shop] = $this->createShopWithOwner();
        $broken = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Tape Big', 'name_en' => 'Tape Big', 'code' => 'টেপবড়']);
        Product::create(['shop_id' => $shop->id, 'category_id' => $broken->id, 'name' => 'টেপবড়-70001', 'name_en' => 'টেপবড়-70001', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        $fixed = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'name_en' => 'Ladies', 'code' => 'L']);

        $this->artisan('articles:list-broken-categories', ['--shop' => $shop->id])
            ->expectsOutputToContain((string) $broken->id)
            ->assertSuccessful();
    }

    public function test_a_shop_with_nothing_broken_is_reported_cleanly(): void
    {
        [$shop] = $this->createShopWithOwner();
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'name_en' => 'Ladies', 'code' => 'L']);

        $this->artisan('articles:list-broken-categories', ['--shop' => $shop->id])->assertSuccessful();
    }

    public function test_requires_shop_or_name(): void
    {
        $this->artisan('articles:list-broken-categories')->assertFailed();
    }
}
