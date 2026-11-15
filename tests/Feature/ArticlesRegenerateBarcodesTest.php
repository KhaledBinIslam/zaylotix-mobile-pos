<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
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

    /**
     * Baby Park live: "JUMP-টেপবড়-70001..." — a category code typed in
     * Bengali (before createArticle() validated it) meant product.barcode
     * itself was never printable, and this pass used to mint a colour
     * barcode from it anyway, with no check at all — unlike the variant
     * loop right above it, which already refused. Confirms the colour
     * pass now refuses too, and is clearly reported apart from "this
     * colour has no English code" (which would send the owner to fix the
     * wrong setting).
     */
    public function test_a_product_with_an_unprintable_own_barcode_is_reported_not_silently_given_a_broken_colour_barcode(): void
    {
        [$shop] = $this->createShopWithOwner();
        // simulating the pre-validation bug directly on the row: a
        // category code that was never ASCII-checked at creation time
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'টেপবড়-10001', 'name_en' => 'টেপবড়-10001', 'barcode' => 'SMS-টেপবড়-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])->assertSuccessful();

        $this->assertNull(ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->where('color', 'SILVER')->first());
    }

    /**
     * Baby Park live ("WHITE-BLUE" variant stuck printing "ZV10239" instead
     * of a real code): BarcodeLabelController::generateVariantBarcode()
     * only ever tries once (gated on `! $variant->barcode`, and the ZV
     * fallback itself is non-empty) — so a colour missing its short code
     * at the moment a label was first printed stays stuck on ZV{id}
     * forever, even after the owner adds that colour's code afterward.
     * --retry-zv-fallback is the one-time repair: an unprintable Bengali
     * colour name is the same root cause ArticleBarcode's own docblock
     * documents ("বাদামী"), and is exactly what a config gap looks like
     * before/after being fixed in ⚙️ রং-কোড সেটিংস.
     */
    public function test_retry_zv_fallback_fixes_a_variant_once_its_missing_color_code_is_added(): void
    {
        [$shop] = $this->createShopWithOwner();
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'name_en' => 'Shoe', 'code' => 'S']);
        $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'S-10001', 'name_en' => 'S-10001', 'barcode' => 'SMS-S-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'বাদামী', 'barcode' => 'ZV'.random_int(1000, 9999), 'stock' => 5]);
        $stuckBarcode = $variant->barcode;

        // not yet configured — still stuck, --retry-zv-fallback changes nothing
        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--retry-zv-fallback' => true, '--variants-only' => true])
            ->assertSuccessful();
        $this->assertSame($stuckBarcode, $variant->fresh()->barcode);

        $shop->update(['color_code_map' => ['বাদামী' => 'BRN']]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--retry-zv-fallback' => true, '--variants-only' => true])
            ->expectsOutputToContain('SMS-S-10001-NSXXZ-BRN-36')
            ->assertSuccessful();

        $this->assertSame('SMS-S-10001-NSXXZ-BRN-36', $variant->fresh()->barcode);
    }

    /**
     * Baby Park live: product "80001" (named without its category's "S-"
     * prefix, so ArticleVariant::isArticle() never recognizes it as an
     * article at all) has working siblings like BLACK-RED carrying real
     * "CHINA-S-80001-IXXZ-BLACK-RED-40" codes — only WHITE-BLUE, added
     * later, is stuck on ZV. --retry-zv-fallback must infer the real
     * prefix from a working sibling rather than require isArticle() at all.
     */
    public function test_retry_zv_fallback_infers_the_prefix_from_a_sibling_when_the_product_itself_is_not_recognized_as_an_article(): void
    {
        [$shop] = $this->createShopWithOwner();
        // deliberately NOT "S-80001" — isArticle() will return false for this product
        $product = Product::create(['shop_id' => $shop->id, 'name' => '80001', 'name_en' => '80001', 'barcode' => '80001-IXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 10]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'BLACK-RED', 'barcode' => 'CHINA-S-80001-IXXZ-BLACK-RED-40', 'stock' => 5]);
        $stuck = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'WHITE-BLUE', 'barcode' => 'ZV'.random_int(1000, 9999), 'stock' => 5]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--retry-zv-fallback' => true, '--variants-only' => true])
            ->expectsOutputToContain('CHINA-S-80001-IXXZ-WHITE-BLUE-40')
            ->assertSuccessful();

        $this->assertSame('CHINA-S-80001-IXXZ-WHITE-BLUE-40', $stuck->fresh()->barcode);
    }

    public function test_without_retry_zv_fallback_a_stuck_zv_barcode_is_left_alone_even_once_fixable(): void
    {
        [$shop] = $this->createShopWithOwner(['color_code_map' => ['বাদামী' => 'BRN']]);
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'name_en' => 'Shoe', 'code' => 'S']);
        $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'S-10001', 'name_en' => 'S-10001', 'barcode' => 'SMS-S-10001-NSXXZ', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'বাদামী', 'barcode' => 'ZV9999', 'stock' => 5]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--variants-only' => true])->assertSuccessful();

        $this->assertSame('ZV9999', $variant->fresh()->barcode);
    }

    public function test_rejects_a_missing_shop(): void
    {
        $this->artisan('articles:regenerate-barcodes', ['--shop' => 999999])
            ->expectsOutputToContain('পাওয়া যায়নি')
            ->assertFailed();
    }
}
