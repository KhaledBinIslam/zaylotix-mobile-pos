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

    /**
     * Once a shop turns "বারকোডে সাইজও যোগ করুন" on, a scan must always
     * resolve straight to one exact size+color -- the ambiguous "one
     * sticker per color" option (scan, then pick a size) must disappear
     * from the print page entirely, or a cashier can still pick it by
     * accident and recreate the exact problem the setting exists to fix.
     */
    public function test_color_only_barcodes_are_hidden_once_barcode_include_size_is_on(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['barcode_include_size' => true]);
        $this->grantFeature($shop, 'barcode_printing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 8]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);
        ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products.0.color_barcodes', 0) // hidden -- the ambiguous option is gone
            ->where('products.0.variants.0.color', 'SILVER') // per-size printing is still there, unaffected
        );
    }

    public function test_color_only_barcodes_still_show_when_barcode_include_size_is_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['barcode_include_size' => false]);
        $this->grantFeature($shop, 'barcode_printing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 8]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'stock' => 5]);
        ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('products.0.color_barcodes', 1));
    }
}
