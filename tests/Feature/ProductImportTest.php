<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Bulk CSV upload for shops with too many SKUs to type in one at a time
 * (a supershop or pharmacy). Matches existing products by barcode within
 * the shop to decide create-vs-update; rows with problems are skipped
 * individually rather than failing the whole file.
 */
class ProductImportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private const HEADER = "name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color\n";

    private function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', self::HEADER.$rows);
    }

    public function test_owner_can_bulk_create_products_with_category_and_unit_auto_created(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $file = $this->csv("চিনি,Sugar,1234567890,মুদি,কেজি,60,70,,100,10,,\n");

        $this->actingAs($owner, 'web')->post('/app/products/import', ['file' => $file])
            ->assertRedirect();

        $product = Product::where('barcode', '1234567890')->first();
        $this->assertNotNull($product);
        $this->assertSame('চিনি', $product->name);
        $this->assertEquals(60.0, (float) $product->cost);
        $this->assertEquals(70.0, (float) $product->price);
        $this->assertEquals(100, $product->stock);
        $this->assertSame('মুদি', $product->category->name);
        $this->assertSame('কেজি', $product->unit->name);
    }

    public function test_a_row_matching_an_existing_barcode_updates_it_instead_of_duplicating(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Old Name', 'barcode' => '999', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $file = $this->csv("New Name,,999,,,15,25,,50,,,\n");
        $this->actingAs($owner, 'web')->post('/app/products/import', ['file' => $file])->assertRedirect();

        $this->assertSame(1, Product::where('barcode', '999')->count());
        $product->refresh();
        $this->assertSame('New Name', $product->name);
        $this->assertEquals(50, $product->stock);
        $this->assertEquals(15.0, (float) $product->cost);
    }

    public function test_invalid_rows_are_skipped_but_valid_rows_in_the_same_file_still_import(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $rows = "Good One,,111,,,10,20,,5,,,\n"
            .",,222,,,10,20,,5,,,\n" // missing name
            ."Bad Cost,,333,,,abc,20,,5,,,\n"; // non-numeric cost

        $file = $this->csv($rows);
        $this->actingAs($owner, 'web')->post('/app/products/import', ['file' => $file])->assertRedirect();

        $this->assertSame(1, Product::count());
        $this->assertNotNull(Product::where('barcode', '111')->first());
    }

    public function test_a_variant_products_stock_is_not_overwritten_by_import(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'barcode' => '555', 'cost' => 100, 'price' => 200, 'stock' => 20]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 20, 'price' => 200, 'cost' => 100]);

        $file = $this->csv("Shirt Updated,,555,,,110,220,,999,,,\n");
        $this->actingAs($owner, 'web')->post('/app/products/import', ['file' => $file])->assertRedirect();

        $product->refresh();
        $this->assertSame('Shirt Updated', $product->name); // name/price still update
        $this->assertEquals(20, $product->stock); // stock untouched — still the variant sum
    }

    public function test_a_barcode_belonging_to_another_shop_is_not_updated_across_tenants(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $otherShopsProduct = Product::create(['shop_id' => $shopB->id, 'name' => 'Other Shops Rice', 'barcode' => '777', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $file = $this->csv("My Rice,,777,,,10,20,,5,,,\n");
        $this->actingAs($ownerA, 'web')->post('/app/products/import', ['file' => $file])->assertRedirect();

        $otherShopsProduct->refresh();
        $this->assertSame('Other Shops Rice', $otherShopsProduct->name); // untouched
        $this->assertSame(2, Product::withoutGlobalScopes()->where('barcode', '777')->count()); // a new one created for shop A instead
    }

    public function test_template_download_returns_a_csv_with_the_expected_header(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/app/products/import/template');

        $response->assertOk();
        $this->assertStringContainsString('name,name_en,barcode,category,unit,cost,price,discount_price,stock,reorder_point,size,color', $response->streamedContent());
    }
}
