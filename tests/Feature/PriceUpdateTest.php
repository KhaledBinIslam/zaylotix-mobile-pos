<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class PriceUpdateTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function anArticleWithVariant(Shop $shop): array
    {
        $category = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Baby', 'name_en' => 'Baby', 'code' => 'B', 'serial_start' => 20000]);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Jump Industries', 'short_code' => 'JUMP']);
        $product = Product::create([
            'shop_id' => $shop->id, 'category_id' => $category->id, 'supplier_id' => $supplier->id,
            'name' => 'B-20001', 'name_en' => 'B-20001', 'barcode' => 'JUMP-B-20001-NSXXZ',
            'cost' => 1300, 'price' => 1990, 'stock' => 10,
        ]);
        $variant = ProductVariant::create([
            'shop_id' => $shop->id, 'product_id' => $product->id,
            'size' => '38', 'color' => 'WHITE', 'barcode' => 'JUMP-B-20001-NSXXZ-WHT-38',
            'cost' => 1300, 'price' => 1990, 'stock' => 4,
        ]);

        return [$product, $variant];
    }

    public function test_index_lists_products_with_their_variants(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product, $variant] = $this->anArticleWithVariant($shop);

        $response = $this->actingAs($owner)->get(route('app.prices.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('App/Prices/Index')
            ->where('products.0.id', $product->id)
            ->where('products.0.variants.0.id', $variant->id));
    }

    public function test_bulk_update_writes_product_cost_and_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product] = $this->anArticleWithVariant($shop);

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [
            'products' => [['id' => $product->id, 'cost' => 1400, 'price' => 2100]],
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(1400.0, (float) $product->cost);
        $this->assertSame(2100.0, (float) $product->price);
    }

    public function test_bulk_update_writes_variant_cost_and_price_independently_of_the_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product, $variant] = $this->anArticleWithVariant($shop);

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [
            'variants' => [['id' => $variant->id, 'cost' => 1350, 'price' => 2050]],
        ])->assertSessionHasNoErrors();

        $variant->refresh();
        $product->refresh();
        $this->assertSame(1350.0, (float) $variant->cost);
        $this->assertSame(2050.0, (float) $variant->price);
        // the product itself was never selected, so its own price is untouched
        $this->assertSame(1300.0, (float) $product->cost);
    }

    public function test_bulk_update_rebuilds_the_article_barcode_when_cost_changes_and_aliases_the_old_one(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product] = $this->anArticleWithVariant($shop);
        $oldBarcode = $product->barcode;
        // a real article already has its color barcode resolved at creation
        // time (or by a prior regenerate-barcodes run) — without this row,
        // there's nothing for resolve() to alias, it would just create a
        // first-ever row instead
        $oldColorBarcode = ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'WHITE', 'barcode' => $oldBarcode.'-WHT']);

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [
            'products' => [['id' => $product->id, 'cost' => 1500, 'price' => 2200]],
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertNotSame($oldBarcode, $product->barcode);

        $colorBarcode = ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->where('color', 'WHITE')->first();
        $this->assertNotNull($colorBarcode);
        $this->assertStringStartsWith($product->barcode, $colorBarcode->barcode);

        $alias = ProductColorBarcodeAlias::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertNotNull($alias);
        $this->assertSame($oldColorBarcode->barcode, $alias->barcode);
    }

    public function test_bulk_update_never_touches_an_already_printed_variant_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product, $variant] = $this->anArticleWithVariant($shop);
        $printedBarcode = $variant->barcode;

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [
            'products' => [['id' => $product->id, 'cost' => 1500, 'price' => 2200]],
        ])->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame($printedBarcode, $variant->barcode);
    }

    public function test_bulk_update_skips_a_branch_copy_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product] = $this->anArticleWithVariant($shop);
        $master = Product::create(['shop_id' => $shop->id, 'name' => 'Master Copy', 'name_en' => 'Master Copy', 'cost' => 1300, 'price' => 1990, 'stock' => 0]);
        $product->update(['master_product_id' => $master->id]);

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [
            'products' => [['id' => $product->id, 'cost' => 1400, 'price' => 2100]],
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(1300.0, (float) $product->cost);
    }

    public function test_rejects_a_product_id_belonging_to_another_shop(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        [$productB] = $this->anArticleWithVariant($shopB);

        $this->actingAs($ownerA)->post(route('app.prices.bulkUpdate'), [
            'products' => [['id' => $productB->id, 'cost' => 1400, 'price' => 2100]],
        ])->assertSessionHasErrors();

        $productB->refresh();
        $this->assertSame(1300.0, (float) $productB->cost);
    }

    public function test_bulk_update_logs_an_activity_entry_for_a_changed_product_and_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        [$product, $variant] = $this->anArticleWithVariant($shop);

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [
            'products' => [['id' => $product->id, 'cost' => 1400, 'price' => 2100]],
            'variants' => [['id' => $variant->id, 'cost' => 1350, 'price' => 2050]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ActivityLog::where('subject_type', Product::class)->where('subject_id', $product->id)->where('action', 'product.priceUpdate')->count());
        $this->assertSame(1, ActivityLog::where('subject_type', ProductVariant::class)->where('subject_id', $variant->id)->where('action', 'product.variant.priceUpdate')->count());
    }

    public function test_requires_at_least_one_selection(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner)->post(route('app.prices.bulkUpdate'), [])
            ->assertSessionHasErrors('selection');
    }
}
