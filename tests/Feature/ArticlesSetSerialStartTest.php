<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ArticlesSetSerialStartTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_moves_the_band_and_the_next_suggestion_continues_the_live_articles(): void
    {
        [$shop] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 80003]);
        Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'B-20001', 'name_en' => 'B-20001', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'B-20061', 'name_en' => 'B-20061', 'cost' => 100, 'price' => 150, 'stock' => 0]);
        Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'B-80003', 'name_en' => 'B-80003', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->artisan('articles:set-serial-start', [
            '--category-id' => $category->id, '--start' => 20000,
        ])->assertSuccessful();

        $this->assertSame(20000, $category->fresh()->serial_start);

        $shop->refresh();
        // 20000 itself was never used (the series starts at 20001), and that
        // is exactly why it must NOT be offered: Baby Park's form suggested
        // B-20000 while the series stood at B-20066 (2026-11-24). A number
        // is only a gap once the series has gone past it — here 20002, the
        // first hole between 20001 and 20061.
        $this->assertSame(20002, \App\Support\ArticleSerial::nextFree($shop, 'B', 20000));
        // and the counter floor was bumped past the live band so a FRESH
        // article (not reusing the 20000 gap) continues past 20061, not 80003
        $this->assertSame(20062, \App\Support\ArticleSerial::peekNext($shop, 'B', 20000));
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 80003]);

        $this->artisan('articles:set-serial-start', [
            '--category-id' => $category->id, '--start' => 20000, '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(80003, $category->fresh()->serial_start);
    }

    public function test_requires_both_options(): void
    {
        [$shop] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B']);

        $this->artisan('articles:set-serial-start', ['--category-id' => $category->id])->assertFailed();
    }

    public function test_reports_an_unknown_category_id_not_errors_silently(): void
    {
        $this->artisan('articles:set-serial-start', ['--category-id' => 999999, '--start' => 20000])->assertFailed();
    }
}
