<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 (source_lines) — the backfill that turns every existing
 * product/variant's current supplier/brand/cost/price/stock/barcode into
 * its one implicit line, without changing a single existing read path.
 */
class SeedSourceLinesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_backfills_one_line_per_variant_copying_its_current_values(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $supplier = Supplier::create(['name' => 'Manik']);
        $brand = Brand::create(['name' => 'Lotus']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 600, 'price' => 1290, 'stock' => 0, 'supplier_id' => $supplier->id]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 6, 'cost' => 650, 'price' => 1290, 'brand_id' => $brand->id, 'barcode' => 'ZV-EXISTING']);

        $this->artisan('products:seed-source-lines', ['--shop' => $shop->id])->assertSuccessful();

        $line = ProductSource::where('product_variant_id', $variant->id)->sole();
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame($supplier->id, $line->supplier_id); // inherited from the product (variants have no supplier column)
        $this->assertSame($brand->id, $line->brand_id); // variant's own brand
        $this->assertEquals(650, (float) $line->cost);
        $this->assertEquals(1290, (float) $line->price);
        $this->assertEquals(6, (float) $line->stock);
        $this->assertSame('ZV-EXISTING', $line->barcode); // REUSED, never regenerated
    }

    public function test_backfills_one_line_for_a_plain_variantless_product(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Torch', 'cost' => 130, 'price' => 250, 'stock' => 10, 'barcode' => 'ZP-EXISTING']);

        $this->artisan('products:seed-source-lines', ['--shop' => $shop->id])->assertSuccessful();

        $line = ProductSource::where('product_id', $product->id)->whereNull('product_variant_id')->sole();
        $this->assertEquals(10, (float) $line->stock);
        $this->assertSame('ZP-EXISTING', $line->barcode);
    }

    public function test_is_idempotent_and_only_backfills_what_is_missing(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 5]);

        $this->artisan('products:seed-source-lines', ['--shop' => $shop->id])->assertSuccessful();
        $this->assertSame(1, ProductSource::count());

        // a brand-new variant added after the first run
        $second = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '43', 'stock' => 3]);

        $this->artisan('products:seed-source-lines', ['--shop' => $shop->id])->assertSuccessful();

        $this->assertSame(2, ProductSource::count()); // only the new one was added
        $this->assertSame($variant->id, ProductSource::where('product_variant_id', $variant->id)->sole()->product_variant_id);
        $this->assertNotNull(ProductSource::where('product_variant_id', $second->id)->first());
    }
}
