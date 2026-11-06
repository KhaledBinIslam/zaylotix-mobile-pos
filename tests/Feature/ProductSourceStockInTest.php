<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\StockIntake;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 (source_lines) — stock-in for a supplier+brand LOT: merges into
 * a matching line or creates one, keeps the variant/product mirror in
 * sync in the same transaction, and writes the intake ledger pinned to
 * that exact line.
 */
class ProductSourceStockInTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aLineShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['source_lines', 'product_variants', 'suppliers', 'brands'] as $f) {
            $this->grantFeature($shop, $f);
        }

        return [$shop, $owner];
    }

    public function test_a_new_supplier_creates_a_new_line_and_updates_the_mirror(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        $manik = Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 0, 'price' => 1290, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 0]);

        $this->post(route('app.productSources.stockInVariant', $variant->id), [
            'supplier_id' => $manik->id, 'qty' => 4, 'cost' => 600,
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_variant_id', $variant->id)->sole();
        $this->assertSame($manik->id, $line->supplier_id);
        $this->assertNull($line->brand_id); // Nil, never asked for
        $this->assertEquals(4, (float) $line->stock);
        $this->assertEquals(600, (float) $line->cost);
        $this->assertEquals(1290, (float) $line->price); // defaulted from the variant's current price
        $this->assertSame('ZL'.$line->id, $line->barcode); // printable immediately

        // mirror kept in sync, in the same transaction
        $this->assertEquals(4, (float) $variant->fresh()->stock);
        $this->assertEquals(600, (float) $variant->fresh()->cost);
        $this->assertEquals(4, (float) $product->fresh()->stock);

        $ledgerRow = StockIntake::where('product_variant_id', $variant->id)->sole();
        $this->assertSame($line->id, $ledgerRow->product_source_id);
        $this->assertSame($manik->id, $ledgerRow->supplier_id);
        $this->assertEquals(600, (float) $ledgerRow->unit_cost); // the price actually PAID
    }

    public function test_the_same_supplier_and_brand_merges_into_the_existing_line_blending_its_own_cost(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        $manik = Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 0, 'price' => 1290, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 0]);

        $this->post(route('app.productSources.stockInVariant', $variant->id), ['supplier_id' => $manik->id, 'qty' => 2, 'cost' => 600])->assertSessionHasNoErrors();
        $this->post(route('app.productSources.stockInVariant', $variant->id), ['supplier_id' => $manik->id, 'qty' => 2, 'cost' => 700])->assertSessionHasNoErrors();

        $this->assertSame(1, ProductSource::where('product_variant_id', $variant->id)->count()); // merged, not duplicated
        $line = ProductSource::where('product_variant_id', $variant->id)->sole();
        $this->assertEquals(4, (float) $line->stock);
        $this->assertEquals(650, (float) $line->cost); // (2*600 + 2*700) / 4 — THIS line's own blend
        $this->assertEquals(4, (float) $variant->fresh()->stock);
        $this->assertEquals(650, (float) $variant->fresh()->cost); // mirror = this line's own blended cost (not a cross-line blend)
    }

    public function test_a_different_supplier_or_brand_creates_a_separate_line_never_a_duplicate(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        $manik = Supplier::create(['name' => 'Manik']);
        $bulu = Supplier::create(['name' => 'Bulu']);
        $lotus = Brand::create(['name' => 'Lotus']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 0, 'price' => 1290, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Orange', 'size' => 'M', 'stock' => 0]);

        $this->post(route('app.productSources.stockInVariant', $variant->id), ['supplier_id' => $manik->id, 'qty' => 4, 'cost' => 600])->assertSessionHasNoErrors();
        $this->post(route('app.productSources.stockInVariant', $variant->id), ['supplier_id' => $bulu->id, 'qty' => 2, 'cost' => 650, 'price' => 1400])->assertSessionHasNoErrors();
        $this->post(route('app.productSources.stockInVariant', $variant->id), ['supplier_id' => $manik->id, 'brand_id' => $lotus->id, 'qty' => 1, 'cost' => 610])->assertSessionHasNoErrors();

        $this->assertSame(3, ProductSource::where('product_variant_id', $variant->id)->count());
        $this->assertEquals(7, (float) $variant->fresh()->stock); // 4+2+1
        $buluLine = ProductSource::where('product_variant_id', $variant->id)->where('supplier_id', $bulu->id)->sole();
        $this->assertEquals(1400, (float) $buluLine->price); // its OWN explicit sell price, independent of Manik's
    }

    public function test_tenant_scoped_supplier_and_brand_quietly_become_nil(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 0, 'price' => 200, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 0]);

        [$shopB] = $this->createShopWithOwner();
        $foreignSupplier = Supplier::withoutGlobalScopes()->create(['shop_id' => $shopB->id, 'name' => 'Foreign']);

        $this->post(route('app.productSources.stockInVariant', $variant->id), [
            'supplier_id' => $foreignSupplier->id, 'qty' => 1, 'cost' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertNull(ProductSource::sole()->supplier_id);
    }

    public function test_the_feature_gates_the_endpoint_entirely(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // no source_lines feature
        $this->grantFeature($shop, 'product_variants');
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 0, 'price' => 200, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 0]);

        $this->post(route('app.productSources.stockInVariant', $variant->id), ['qty' => 1, 'cost' => 100])->assertForbidden();
        $this->assertSame(0, ProductSource::count());
    }

    /** Part 1 decision: weighted_average_costing stops blending the MIRROR once source_lines is also on — each supplier's true cost now lives at the line level instead (see Shop::usesWeightedAverageCosting()). */
    public function test_weighted_average_costing_stops_blending_the_mirror_once_source_lines_is_also_on(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $this->grantFeature($shop, 'weighted_average_costing');
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 1]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 1, 'cost' => 1600]);

        // the OLD (pre-Part-1) stock-in endpoint still exists and still
        // works — it just no longer blends once source_lines is also on
        $this->post(route('app.productVariants.stockIn', $variant->id), ['qty' => 5, 'cost' => 1750])->assertSessionHasNoErrors();

        $this->assertEquals(1750, (float) $variant->fresh()->cost); // overwrote, did NOT blend to 1725
    }

    public function test_a_plain_variantless_products_own_line_works_the_same_way(): void
    {
        [$shop, $owner] = $this->aLineShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);
        $torch = Supplier::create(['name' => 'Torch Supplier']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Torch', 'cost' => 0, 'price' => 250, 'stock' => 0]);

        $this->post(route('app.productSources.stockInProduct', $product->id), [
            'supplier_id' => $torch->id, 'qty' => 5, 'cost' => 130,
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_id', $product->id)->whereNull('product_variant_id')->sole();
        $this->assertEquals(5, (float) $line->stock);
        $this->assertEquals(5, (float) $product->fresh()->stock);
        $this->assertEquals(130, (float) $product->fresh()->cost);
    }
}
