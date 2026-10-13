<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The Smart Scan sheet checks this the instant a barcode is decoded, before
 * the owner types anything else into a new-product form — matches a plain
 * product's own barcode first, then falls back to a variant's (a scanned
 * clothing/shoe label is usually the variant's own barcode, not the
 * parent product's).
 */
class SmartScanBarcodeLookupTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_finds_an_existing_plain_product_by_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Existing Rice', 'cost' => 10, 'price' => 20, 'stock' => 50, 'barcode' => '8901234567890']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/products/barcode-lookup/8901234567890');

        $response->assertOk();
        $response->assertJson(['exists' => true]);
        $this->assertSame($product->id, $response->json('product.id'));
    }

    public function test_finds_an_existing_variant_by_its_own_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 5, 'barcode' => 'SHIRT-M-BLUE']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/products/barcode-lookup/SHIRT-M-BLUE');

        $response->assertOk();
        $response->assertJson(['exists' => true]);
        $this->assertSame($product->id, $response->json('product.id'));
        $this->assertSame('M, Blue', $response->json('product.variant_label'));
    }

    public function test_reports_not_found_for_an_unknown_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Something Else', 'cost' => 10, 'price' => 20, 'stock' => 1, 'barcode' => '1111111111111']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/products/barcode-lookup/9999999999999');

        $response->assertOk();
        $response->assertJson(['exists' => false, 'product' => null]);
    }

    public function test_lookup_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopB->id, 'name' => 'Other Shops Product', 'cost' => 10, 'price' => 20, 'stock' => 1, 'barcode' => 'SHARED-CODE']);

        $response = $this->actingAs($ownerA, 'web')->getJson('/app/products/barcode-lookup/SHARED-CODE');

        $response->assertOk();
        $response->assertJson(['exists' => false]);
    }
}
