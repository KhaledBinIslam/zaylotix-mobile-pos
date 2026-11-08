<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN live: a variant deleted while its supplier lot (source line)
 * still had real stock left that lot active and pointing at a now-gone
 * variant — "ZV{variantId}" is exactly what a lot's own printed sticker
 * encodes, so the physical goods on the shelf kept scanning "not found"
 * even though the system still thought 8 pieces of it existed. destroy()
 * now soft-deletes the variant's source lines right alongside it, so
 * nothing is ever left orphaned.
 */
class ProductVariantDestroySourceCascadeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_destroying_a_variant_also_soft_deletes_its_active_source_lines(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'source_lines');
        $this->grantFeature($shop, 'suppliers');

        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 8]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'XXL', 'color' => 'Grey', 'stock' => 8]);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu']);
        $source = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $supplier->id, 'cost' => 650, 'price' => 1090, 'stock' => 8]);

        $this->actingAs($owner, 'web')->delete(route('app.productVariants.destroy', $v->id))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(ProductVariant::withTrashed()->find($v->id)->deleted_at);
        $this->assertNotNull(ProductSource::withTrashed()->find($source->id)->deleted_at); // no longer an orphaned, still-active lot
        $this->assertEquals(0, $p->fresh()->stock);
    }

    public function test_restoring_a_variant_brings_its_barcode_back_to_a_working_scan(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');

        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 8]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'XXL', 'color' => 'Grey', 'stock' => 8]);

        $this->actingAs($owner, 'web')->delete(route('app.productVariants.destroy', $v->id));
        $this->assertSame(404, $this->actingAs($owner, 'web')->getJson(route('app.pos.barcode', 'ZV'.$v->id))->status());

        // the exact fix applied live on Seaman: restore + put the stock back
        $trashedVariant = ProductVariant::withTrashed()->find($v->id);
        $trashedVariant->restore();
        $p->fresh()->applyStockDelta($trashedVariant->stock);

        $this->actingAs($owner, 'web')->getJson(route('app.pos.barcode', 'ZV'.$v->id))
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('variant_id', $v->id);
        $this->assertEquals(8, $p->fresh()->stock);
    }
}
