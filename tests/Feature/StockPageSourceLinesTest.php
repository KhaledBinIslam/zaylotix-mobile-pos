<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Part 1 (source_lines) — the Stock page (every shop's most-visited
 * screen) must render identically for a shop without the feature, and
 * carry each variant's lines for one that has it. A regression here
 * would be the single most damaging mistake this change could make.
 */
class StockPageSourceLinesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_shop_without_the_feature_sees_no_sources_prop_change(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 5]);

        $this->actingAs($owner, 'web')->get(route('app.stock'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('App/Stock/Index')
                ->where('products.data.0.id', $product->id)
            );
    }

    public function test_a_shop_with_the_feature_gets_each_variants_lines(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['source_lines', 'product_variants', 'suppliers'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $supplier = \App\Models\Supplier::create(['name' => 'Manik']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 5]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $supplier->id, 'cost' => 100, 'price' => 200, 'stock' => 5]);

        $this->actingAs($owner, 'web')->get(route('app.stock'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('App/Stock/Index')
                ->where('products.data.0.variants.0.sources.0.supplier.name', 'Manik')
            );
    }
}
