<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** docs/ARTICLE_SERIES_PLAN.md §9 — backfills color barcodes for articles imported before this feature existed. */
class ArticlesRegenerateBarcodesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creates_a_color_barcode_for_every_color_a_product_has(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'name_en' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 11]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '37', 'color' => 'SILVER', 'stock' => 3]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'KHAKI', 'stock' => 3]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])
            ->expectsOutputToContain('2টা নতুন, 0টা বদলানো, 0টা অপরিবর্তিত।')
            ->assertSuccessful();

        $this->assertSame(2, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());
        $silver = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->where('color', 'SILVER')->first();
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER', $silver->barcode);
    }

    public function test_a_second_run_with_nothing_changed_reports_unchanged_and_writes_nothing_new(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'name_en' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])->assertSuccessful();
        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])
            ->expectsOutputToContain('0টা নতুন, 0টা বদলানো, 1টা অপরিবর্তিত।')
            ->assertSuccessful();

        $this->assertSame(1, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    public function test_a_color_code_rename_aliases_the_old_barcode_on_regenerate(): void
    {
        [$shop] = $this->createShopWithOwner(['color_code_map' => ['SILVER' => 'SIL']]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'name_en' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);
        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])->assertSuccessful();

        $shop->update(['color_code_map' => ['SILVER' => 'SLV']]);
        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])
            ->expectsOutputToContain('0টা নতুন, 1টা বদলানো, 0টা অপরিবর্তিত।')
            ->assertSuccessful();

        $current = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertSame('SMS-L-10001-NSXXZ-SLV', $current->barcode);
        $alias = ProductColorBarcodeAlias::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertSame('SMS-L-10001-NSXXZ-SIL', $alias->barcode);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'name_en' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--dry-run' => true])
            ->expectsOutputToContain('প্রিভিউ')
            ->assertSuccessful();

        $this->assertSame(0, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    public function test_a_product_with_no_article_barcode_is_skipped(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'No Barcode Item', 'name_en' => 'No Barcode Item', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Red', 'stock' => 5]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])
            ->expectsOutputToContain('0টা নতুন, 0টা বদলানো, 0টা অপরিবর্তিত।')
            ->assertSuccessful();
    }

    public function test_rejects_a_missing_shop(): void
    {
        $this->artisan('articles:regenerate-barcodes', ['--shop' => 999999])
            ->expectsOutputToContain('পাওয়া যায়নি')
            ->assertFailed();
    }
}
