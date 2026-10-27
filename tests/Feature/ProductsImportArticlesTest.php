<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\ArticleSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * docs/ARTICLE_SERIES_PLAN.md §5/6. Builds a small synthetic xlsx in the
 * exact real shape (confirmed against the actual
 * docs/import/BabyPark_Product_Entry_1.xlsx) rather than depending on
 * that file's exact contents, so these tests stay correct even if Khaled
 * replaces it later. A separate smoke test at the bottom runs --dry-run
 * against the REAL bundled file to catch a gross regression.
 *
 * Uses the array form of artisan($command, $parameters) throughout, not
 * the raw command-line-string form — the string form mis-tokenizes a
 * Windows temp file path, which isn't an interesting thing to depend on.
 */
class ProductsImportArticlesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private array $headers = [
        'MANUFACTURE & PURCHASE', 'name_en', 'barcode', 'category', 'unit', 'cost', 'price',
        'discount_price', 'stock', 'reorder_point', 'size', 'BLACK', 'WHITE', 'BROWN', 'COFFE',
        'SILVER', 'KHAKI', 'GOLDEN', 'MAROON', 'GREY',
    ];

    private function buildXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');
        $sheet->fromArray($this->headers, null, 'A1');
        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 2));
        }

        $path = tempnam(sys_get_temp_dir(), 'article_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    /** A row shaped exactly like the real file's row 2 (blank cost, trailing Z, stock/grid mismatch, "(sold)"). */
    private function rowOne(): array
    {
        return [
            'SMS', 'L10001', 'SMS-L-10001-NSXXZ', 'LADIES', 'PAIR', null, 1990, null, 12, 3, '36-37',
            null, null, null, null,
            '36--(1)---37--(sold)--', null, null, null, null,
        ];
    }

    private function runImport(string $path, int $shopId, bool $dryRun = false): \Illuminate\Testing\PendingCommand
    {
        $params = ['file' => $path, '--shop' => $shopId];
        if ($dryRun) {
            $params['--dry-run'] = true;
        }

        return $this->artisan('products:import-articles', $params);
    }

    public function test_dry_run_reports_counts_without_writing_anything(): void
    {
        [$shop] = $this->createShopWithOwner();
        $path = $this->buildXlsx([$this->rowOne()]);

        $this->runImport($path, $shop->id, dryRun: true)
            ->expectsOutputToContain('আর্টিকেল: 1, ভ্যারিয়েন্ট: 2')
            ->assertSuccessful();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, Supplier::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_real_run_creates_article_variants_and_normalizes_the_name(): void
    {
        [$shop] = $this->createShopWithOwner();
        $path = $this->buildXlsx([$this->rowOne()]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $product = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->first();
        $this->assertNotNull($product);
        // "L10001" (no hyphen) in the file -> normalized from the barcode's own L-10001
        $this->assertSame('L-10001', $product->name_en);
        $this->assertSame('SMS-L-10001-NSXXZ', $product->barcode);
        // cost was blank in the sheet -> decoded from the barcode's NSXXZ -> 1300
        $this->assertEquals(1300, $product->cost);
        $this->assertEquals(1990, $product->price);

        $variants = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->get();
        $this->assertCount(2, $variants);
        $sold = $variants->firstWhere('size', '37');
        $this->assertEquals(0, $sold->stock); // "(sold)" -> 0
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER-36', $variants->firstWhere('size', '36')->barcode);

        // grid sum (1+0=1) used for the product's own stock, NOT the sheet's stock=12
        $this->assertEquals(1, $product->fresh()->stock);

        // Khaled: a shoe is sold by the pair, not "পিস" -- the sheet's own "PAIR" must actually land on the product, not be silently dropped.
        // Queried directly (withoutGlobalScopes), not via $product->unit -- that lazy
        // relation resolves through Unit's own tenant scope, which this console-style
        // test has no ambient Tenancy context for (same gotcha as ProductVariant::product above).
        $unit = \App\Models\Unit::withoutGlobalScopes()->find($product->unit_id);
        $this->assertNotNull($unit);
        $this->assertSame('জোড়া', $unit->name);
        $this->assertSame('Pair', $unit->name_en);
    }

    public function test_imported_articles_get_a_scannable_color_barcode_per_color(): void
    {
        [$shop] = $this->createShopWithOwner();
        $path = $this->buildXlsx([$this->rowOne()]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $product = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->first();
        $silver = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->where('color', 'SILVER')->first();
        $this->assertNotNull($silver);
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER', $silver->barcode);
    }

    public function test_the_unit_is_reused_across_rows_not_recreated_per_row(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row2 = $this->rowOne();
        $row2[1] = 'L10002';
        $row2[2] = 'SMS-L-10002-NSRX';
        $path = $this->buildXlsx([$this->rowOne(), $row2]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $this->assertSame(1, \App\Models\Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'জোড়া')->count());
    }

    public function test_an_existing_category_is_reused_not_duplicated(): void
    {
        [$shop] = $this->createShopWithOwner();
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'মেয়েদের জুতা', 'name_en' => "Women's Shoes"]);
        $path = $this->buildXlsx([$this->rowOne()]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $this->assertSame(1, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $category = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->first();
        $this->assertSame('L', $category->code); // extracted from the barcode's own {CAT} segment
    }

    public function test_a_new_category_is_created_when_no_synonym_matches(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row = $this->rowOne();
        $row[3] = 'EXOTIC'; // not in the synonym table at all
        $path = $this->buildXlsx([$row]);

        $this->runImport($path, $shop->id, dryRun: true)
            ->expectsOutputToContain('নতুন ক্যাটাগরি তৈরি হবে')
            ->assertSuccessful();
    }

    public function test_one_and_sold_means_current_stock_is_one(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row = $this->rowOne();
        $row[11] = '36--(1 & SOLD)--37--(2)--'; // BLACK column
        $path = $this->buildXlsx([$row]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $v = ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->where('color', 'BLACK')->where('size', '36')->first();
        $this->assertEquals(1, $v->stock);
    }

    public function test_a_hash_filled_cell_means_this_article_has_no_such_color(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row = $this->rowOne();
        $row[12] = '#####'; // WHITE column
        $path = $this->buildXlsx([$row]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->where('color', 'WHITE')->count());
    }

    public function test_cost_mismatch_between_sheet_and_barcode_is_reported_but_the_sheets_value_is_kept(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row = $this->rowOne();
        $row[5] = 9999; // doesn't match NSXX's decoded 1300 at all
        $path = $this->buildXlsx([$row]);

        $this->runImport($path, $shop->id, dryRun: true)
            ->expectsOutputToContain('মিসম্যাচ')
            ->assertSuccessful();

        $this->runImport($path, $shop->id)->assertSuccessful();
        $product = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->first();
        $this->assertEquals(9999, $product->cost); // never silently "corrected"
    }

    public function test_an_undecodable_cost_code_digit_is_flagged_not_guessed(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row = $this->rowOne();
        $row[2] = 'SMS-L-10001-NSQX'; // 'Q' is not any known digit's letter in the default map
        $row[5] = null;
        $path = $this->buildXlsx([$row]);

        $this->runImport($path, $shop->id, dryRun: true)
            ->expectsOutputToContain('ডিকোড করা যায়নি')
            ->assertSuccessful();
    }

    public function test_manufacturer_is_reused_across_rows_not_recreated_per_row(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row2 = $this->rowOne();
        $row2[1] = 'L10002';
        $row2[2] = 'SMS-L-10002-NSRX';
        $path = $this->buildXlsx([$this->rowOne(), $row2]);

        $this->runImport($path, $shop->id)->assertSuccessful();

        $this->assertSame(1, Supplier::withoutGlobalScopes()->where('shop_id', $shop->id)->where('short_code', 'SMS')->count());
        $supplierId = Supplier::withoutGlobalScopes()->where('shop_id', $shop->id)->first()->id;
        $this->assertSame(2, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('supplier_id', $supplierId)->count());
    }

    public function test_the_shared_serial_counter_advances_past_the_highest_imported_serial(): void
    {
        [$shop] = $this->createShopWithOwner();
        $row2 = $this->rowOne();
        $row2[1] = 'L10005';
        $row2[2] = 'SMS-L-10005-NJRX';
        $row2[5] = 1650;
        $path = $this->buildXlsx([$this->rowOne(), $row2]); // serials 10001 and 10005 imported

        $this->runImport($path, $shop->id)->assertSuccessful();

        // a live-created article must continue AFTER the highest imported serial, never collide
        $this->assertSame(10006, ArticleSerial::peekNext($shop, 'L'));
    }

    public function test_tenant_isolation_a_shops_import_never_touches_another_shops_rows(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $path = $this->buildXlsx([$this->rowOne()]);

        $this->runImport($path, $shopA->id)->assertSuccessful();

        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $shopA->id)->count());
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shopB->id)->count());
    }

    public function test_dry_run_against_the_real_bundled_file_finds_ten_articles(): void
    {
        $realFile = base_path('docs/import/BabyPark_Product_Entry_1.xlsx');
        if (! is_file($realFile)) {
            $this->markTestSkipped('docs/import/BabyPark_Product_Entry_1.xlsx not present in this checkout.');
        }

        [$shop] = $this->createShopWithOwner();

        $this->runImport($realFile, $shop->id, dryRun: true)
            ->expectsOutputToContain('আর্টিকেল: 10, ভ্যারিয়েন্ট: 100')
            ->assertSuccessful();
    }
}
