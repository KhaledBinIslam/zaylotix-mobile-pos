<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park's branch listed B-20009 twice over — sixteen sizes under
 * BROWN/PINK and eight more under বাদামী/গোলাপি with the same counts.
 * products.stock read 25 while the sizes summed to 41. Not stock: an
 * old spelling left behind when the colours were renamed.
 */
class ProductsMergeColourTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_size_that_exists_under_both_names_loses_the_old_one(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        $keep = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'BROWN', 'stock' => 2, 'barcode' => 'A-BRN-16']);
        $stale = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'বাদামী', 'stock' => 2]);

        $this->artisan('products:merge-colour', ['--shop' => $shop->id, '--from' => 'বাদামী', '--to' => 'BROWN'])->assertSuccessful();

        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->whereKey($stale->id)->value('deleted_at'));
        $this->assertNull($keep->fresh()->deleted_at);
        $this->assertSame('A-BRN-16', $keep->fresh()->barcode);
        // the sizes now sum to what the product always said it held
        $this->assertEquals(2, ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->whereNull('deleted_at')->sum('stock'));
    }

    public function test_a_size_only_the_old_name_has_is_renamed_and_keeps_its_stock(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $only = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '17', 'color' => 'বাদামী', 'stock' => 3, 'barcode' => 'A-BN-17']);

        $this->artisan('products:merge-colour', ['--shop' => $shop->id, '--from' => 'বাদামী', '--to' => 'BROWN'])->assertSuccessful();

        $after = $only->fresh();
        $this->assertSame('BROWN', $after->color);
        $this->assertEquals(3, $after->stock);
        $this->assertNull($after->deleted_at);
        // the barcode carried the old colour's code, so it is released
        // for articles:regenerate-barcodes to mint afresh
        $this->assertNull($after->barcode);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'BROWN', 'stock' => 2]);
        $stale = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'বাদামী', 'stock' => 2]);

        $this->artisan('products:merge-colour', ['--shop' => $shop->id, '--from' => 'বাদামী', '--to' => 'BROWN', '--dry-run' => true])->assertSuccessful();

        $this->assertNull($stale->fresh()->deleted_at);
        $this->assertSame('বাদামী', $stale->fresh()->color);
    }

    public function test_another_colour_is_never_touched(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $other = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'WHITE', 'stock' => 5, 'barcode' => 'A-WHT-16']);

        $this->artisan('products:merge-colour', ['--shop' => $shop->id, '--from' => 'বাদামী', '--to' => 'BROWN'])->assertSuccessful();

        $this->assertSame('WHITE', $other->fresh()->color);
        $this->assertSame('A-WHT-16', $other->fresh()->barcode);
    }

    public function test_ids_can_name_the_rows_so_a_bengali_colour_never_has_to_be_typed(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 200, 'stock' => 2]);
        $keep = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'BROWN', 'stock' => 2]);
        $stale = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '16', 'color' => 'বাদামী', 'stock' => 2]);
        $untouched = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '17', 'color' => 'বাদামী', 'stock' => 1]);

        // only the listed row goes; the one left out keeps its name
        $this->artisan('products:merge-colour', ['--shop' => $shop->id, '--ids' => (string) $stale->id, '--to' => 'BROWN'])->assertSuccessful();

        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->whereKey($stale->id)->value('deleted_at'));
        $this->assertNull($keep->fresh()->deleted_at);
        $this->assertSame('বাদামী', $untouched->fresh()->color);
    }
}
