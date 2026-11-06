<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockIntake;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 4 — brands (per product, overridable per variant), the
 * supplier on every intake, and the gated weighted-average costing. A
 * shop without the features behaves exactly as before, pinned below.
 */
class BrandTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aBrandShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'brands');
        $this->grantFeature($shop, 'product_variants');

        return [$shop, $owner];
    }

    public function test_brand_crud_and_merge_repoint_products_and_variants(): void
    {
        [$shop, $owner] = $this->aBrandShop();
        $this->actingAs($owner, 'web');

        $this->post(route('app.brands.store'), ['name' => 'Safeguard'])->assertSessionHasNoErrors();
        $this->post(route('app.brands.store'), ['name' => 'Safe Guard'])->assertSessionHasNoErrors();
        [$good, $dupe] = Brand::orderBy('id')->get();

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'cost' => 100, 'price' => 200, 'stock' => 0, 'brand_id' => $dupe->id]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'White', 'stock' => 1, 'brand_id' => $dupe->id]);

        $this->post(route('app.brands.merge', $dupe->id), ['into' => $good->id])->assertSessionHasNoErrors();

        $this->assertSame($good->id, $product->fresh()->brand_id);
        $this->assertSame($good->id, $variant->fresh()->brand_id);
        $this->assertNull(Brand::find($dupe->id));

        // delete: products keep living, brand becomes Nil (null)
        $this->delete(route('app.brands.destroy', $good->id))->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_brand_page_is_refused_without_the_feature_and_ids_never_cross_shops(): void
    {
        [, $plainOwner] = $this->createShopWithOwner();
        $this->actingAs($plainOwner, 'web')->get(route('app.brands.index'))->assertForbidden();

        [$shopA, $ownerA] = $this->aBrandShop();
        [$shopB, $ownerB] = $this->aBrandShop();
        $foreign = Brand::withoutGlobalScopes()->create(['shop_id' => $shopB->id, 'name' => 'Foreign']);

        // a foreign brand id on a product silently becomes "no brand" —
        // the Nil rule, never an error and never a cross-tenant pointer
        $this->actingAs($ownerA, 'web')->post(route('app.products.store'), [
            'name' => 'Shoe', 'cost' => 1, 'price' => 2, 'stock' => 0, 'brand_id' => $foreign->id,
        ])->assertSessionHasNoErrors();
        $p = Product::withoutGlobalScopes()->where('shop_id', $shopA->id)->where('name', 'Shoe')->first();
        $this->assertNull($p->brand_id);
    }

    public function test_variant_stock_in_records_the_supplier_in_the_ledger(): void
    {
        [$shop, $owner] = $this->aBrandShop();
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Jubilee']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 1]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 1, 'cost' => 1600]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.stockIn', $variant->id), [
            'qty' => 5, 'cost' => 1750, 'supplier_id' => $supplier->id,
        ])->assertSessionHasNoErrors();

        $row = StockIntake::where('shop_id', $shop->id)->sole();
        $this->assertSame($supplier->id, $row->supplier_id);
        $this->assertEquals(1750, $row->unit_cost); // the ledger keeps the price actually PAID
    }

    public function test_weighted_average_costing_blends_only_with_the_feature(): void
    {
        // WITH the feature: 1 @1600 + 5 @1750 -> 1725
        [$shop, $owner] = $this->aBrandShop();
        $this->grantFeature($shop, 'weighted_average_costing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 1]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 1, 'cost' => 1600]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.stockIn', $variant->id), [
            'qty' => 5, 'cost' => 1750,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(1725, $variant->fresh()->cost);

        // WITHOUT it (Baby Park's world): the new cost overwrites, exactly as before
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'product_variants');
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'L-10001', 'cost' => 500, 'price' => 900, 'stock' => 2]);
        $variantB = ProductVariant::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'size' => '36', 'stock' => 2, 'cost' => 500]);

        $this->actingAs($ownerB, 'web')->post(route('app.productVariants.stockIn', $variantB->id), [
            'qty' => 4, 'cost' => 550,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(550, $variantB->fresh()->cost);
    }

    public function test_brand_report_prefers_the_variants_brand_and_collects_nil(): void
    {
        [$shop, $owner] = $this->aBrandShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        $lotus = Brand::create(['name' => 'Lotus']);
        $crown = Brand::create(['name' => 'Crown']);

        $helmet = Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'cost' => 100, 'price' => 200, 'stock' => 0, 'brand_id' => $lotus->id]);
        $crownVariant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $helmet->id, 'color' => 'White', 'stock' => 0, 'brand_id' => $crown->id]);
        $plain = Product::create(['shop_id' => $shop->id, 'name' => 'Torch', 'cost' => 130, 'price' => 250, 'stock' => 0]);

        $sale = Sale::create(['shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => '10:00', 'subtotal' => 0, 'discount' => 0, 'vat' => 0, 'total' => 0, 'profit' => 0, 'payment_mode' => 'cash', 'counted_in_reports' => true]);
        // variant line -> Crown (variant brand beats the product's Lotus)
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $helmet->id, 'product_variant_id' => $crownVariant->id, 'product_name' => 'Helmet', 'qty' => 2, 'price' => 200, 'discount' => 0, 'cost' => 100]);
        // unbranded line -> Nil
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $plain->id, 'product_name' => 'Torch', 'qty' => 1, 'price' => 250, 'discount' => 0, 'cost' => 130]);

        $report = \App\Support\Reports::brandReport(now()->toDateString(), now()->toDateString());

        $byBrand = collect($report)->keyBy('brand');
        $this->assertEquals(400, $byBrand['Crown']['revenue']);
        $this->assertEquals(200, $byBrand['Crown']['profit']);
        $this->assertEquals(250, $byBrand['Nil']['revenue']);
        $this->assertArrayNotHasKey('Lotus', $byBrand->all()); // nothing sold AS Lotus
    }
}
