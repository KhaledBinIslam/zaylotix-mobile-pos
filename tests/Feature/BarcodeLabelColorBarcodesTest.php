<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** docs/ARTICLE_SERIES_PLAN.md §9 — the barcode-labels page can print one label per color, summing that color's stock across every size. */
class BarcodeLabelColorBarcodesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_color_barcodes_stock_is_the_sum_across_every_size(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 8]);
        $v1 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '37', 'color' => 'SILVER', 'stock' => 3]);
        $cb = ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('products.0.color_barcodes.0.color', 'SILVER')
            ->where('products.0.color_barcodes.0.barcode', 'SMS-L-10001-NSXX-SILVER')
            ->where('products.0.color_barcodes.0.scan_code', 'ZC'.$cb->id)
            ->where('products.0.color_barcodes.0.stock', 8)
            // docs/ARTICLE_SERIES_PLAN.md §10 — the per-variant label also
            // carries its own short scan_code, independent of the color one
            ->where('products.0.variants.0.scan_code', 'ZV'.$v1->id)
        );
    }

    public function test_a_product_with_no_color_barcodes_yet_gets_an_empty_list_not_an_error(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('products.0.color_barcodes', 0));
    }
}
