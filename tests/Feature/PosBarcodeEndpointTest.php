<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** docs/ARTICLE_SERIES_PLAN.md §9 — PosController::barcode()'s three-tier resolution (exact variant, color, article). */
class PosBarcodeEndpointTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_an_exact_variant_barcode_resolves_with_a_variant_id(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/SMS-L-10001-NSXX-SILVER-36');

        $response->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id]);
    }

    public function test_a_color_barcode_resolves_with_the_color_but_no_variant_id(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/SMS-L-10001-NSXX-SILVER');

        $response->assertOk()->assertJson(['found' => true, 'color' => 'SILVER'])
            ->assertJsonMissingPath('variant_id')
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_an_aliased_old_color_barcode_still_resolves(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SLV']);
        ProductColorBarcodeAlias::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SIL', 'created_at' => now()]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/SMS-L-10001-NSXX-SIL');

        $response->assertOk()->assertJson(['found' => true, 'color' => 'SILVER']);
    }

    public function test_the_article_level_barcode_still_resolves_with_no_color_hint(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/SMS-L-10001-NSXX');

        $response->assertOk()->assertJson(['found' => true])->assertJsonMissingPath('color')->assertJsonMissingPath('variant_id');
    }

    public function test_an_unknown_barcode_is_not_found(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/NO-SUCH-CODE');

        $response->assertStatus(404)->assertJson(['found' => false]);
    }

    public function test_tenant_isolation_a_color_barcode_never_resolves_across_shops(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductColorBarcode::create(['shop_id' => $shopA->id, 'product_id' => $productA->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($ownerB, 'web')->getJson('/app/pos/barcode/SMS-L-10001-NSXX-SILVER');

        $response->assertStatus(404);
    }

    /** docs/ARTICLE_SERIES_PLAN.md §10 — the short printed-symbol code a label's bars actually encode, decoupled from the full text still printed below it. */
    public function test_a_short_zv_code_resolves_to_its_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/ZV'.$variant->id);

        $response->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id]);
    }

    public function test_a_short_zc_code_resolves_to_its_color(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $cb = ProductColorBarcode::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/ZC'.$cb->id);

        $response->assertOk()->assertJson(['found' => true, 'color' => 'SILVER'])
            ->assertJsonPath('product.id', $product->id);
    }

    public function test_an_unknown_short_code_is_not_found(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/ZV999999')->assertStatus(404);
        $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/ZC999999')->assertStatus(404);
    }

    public function test_tenant_isolation_a_short_zv_code_never_resolves_across_shops(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'L-10001', 'barcode' => 'SMS-L-10001-NSXX', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        $variantA = ProductVariant::create(['shop_id' => $shopA->id, 'product_id' => $productA->id, 'size' => '36', 'color' => 'SILVER', 'barcode' => 'SMS-L-10001-NSXX-SILVER-36', 'stock' => 5]);

        $response = $this->actingAs($ownerB, 'web')->getJson('/app/pos/barcode/ZV'.$variantA->id);

        $response->assertStatus(404);
    }
}
