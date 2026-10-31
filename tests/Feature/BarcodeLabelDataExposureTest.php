<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * `barcode_labels` can be granted to a cashier independently of `stock` —
 * this page must not ship cost/margin data to a client who may not be
 * allowed to see it, regardless of what the template chooses to render.
 */
class BarcodeLabelDataExposureTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_barcode_label_products_do_not_include_cost(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 55, 'price' => 90, 'stock' => 10, 'barcode' => '1234567890']);

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->missing('products.0.cost')
        );
    }

    /**
     * Stock rides along with every row so the picker can show what is
     * actually in hand — Khaled prints a sticker per pair and was setting
     * counts blind ("jate dekhe print dite pare"). Cost stays out, as the
     * test above pins; stock is not margin data.
     */
    public function test_barcode_label_rows_carry_the_stock_on_hand(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $this->grantFeature($shop, 'product_variants');

        Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 55, 'price' => 90, 'stock' => 7, 'barcode' => '1234567890']);
        $shoe = Product::create(['shop_id' => $shop->id, 'name' => 'B-20009', 'cost' => 1300, 'price' => 1990, 'stock' => 5]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shoe->id, 'size' => '18', 'color' => 'RED', 'stock' => 2, 'barcode' => 'V18RED']);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shoe->id, 'size' => '25', 'color' => 'RED', 'stock' => 3, 'barcode' => 'V25RED']);

        $this->actingAs($owner, 'web')->get('/app/barcode-labels')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.0.name', 'B-20009')
                // closures, not literals: stock is a decimal column, so
                // whether it serializes as 5 or 5.0 is not the point here
                ->where('products.0.stock', fn ($s) => (float) $s === 5.0)
                ->where('products.0.variants.0.stock', fn ($s) => (float) $s === 2.0)
                ->where('products.0.variants.1.stock', fn ($s) => (float) $s === 3.0)
                ->where('products.1.name', 'Soap')
                ->where('products.1.stock', fn ($s) => (float) $s === 7.0)
                // still no cost, whatever else was added
                ->missing('products.0.cost')
            );
    }

    /** A variant product with no barcode of its own (the normal case — see DemoShopSeeder's clothingDemo()) must still show up here once it has variants, not just when its own barcode column happens to be set. */
    public function test_a_variant_product_appears_even_without_its_own_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $this->grantFeature($shop, 'product_variants');
        $shirt = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 400, 'price' => 650, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'M', 'color' => 'Blue', 'barcode' => 'SHIRT-M-BLUE', 'stock' => 10]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'L', 'color' => 'Blue', 'stock' => 5]); // no barcode yet

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        // variants come back ordered by size then color (see
        // BarcodeLabelController::index) — 'L' sorts before 'M'
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->where('products.0.variants.0.barcode', null)
            ->where('products.0.variants.1.barcode', 'SHIRT-M-BLUE')
            ->missing('products.0.variants.1.cost')
        );
    }

    /**
     * Live bug fix — see docs/FIX_PLAN.md: a plain product with neither a
     * barcode nor variants used to be excluded from this page entirely,
     * so a shop whose products never had a barcode assigned (the normal
     * case before ever printing one) saw "No products" here with no way
     * to generate a first barcode at all.
     */
    public function test_a_barcode_less_product_with_no_variants_now_appears(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        Product::create(['shop_id' => $shop->id, 'name' => 'Loose Rice', 'price' => 90, 'stock' => 10]); // no barcode

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->where('products.0.barcode', null)
        );
    }

    public function test_owner_can_generate_a_barcode_for_a_plain_product_that_has_none(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Loose Rice', 'price' => 90, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->patch("/app/products/{$product->id}/barcode/generate");

        $response->assertRedirect();
        $this->assertSame('ZP'.$product->id, $product->refresh()->barcode);
    }

    public function test_generating_a_product_barcode_twice_keeps_the_first_one(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'price' => 90, 'stock' => 10, 'barcode' => 'MANUALLY-SET']);

        $this->actingAs($owner, 'web')->patch("/app/products/{$product->id}/barcode/generate");

        $this->assertSame('MANUALLY-SET', $product->refresh()->barcode);
    }

    public function test_cannot_generate_a_barcode_for_another_shops_product(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'barcode_printing');
        $product = Product::create(['shop_id' => $shopA->id, 'name' => 'Soap', 'price' => 90, 'stock' => 10]);

        $response = $this->actingAs($ownerB, 'web')->patch("/app/products/{$product->id}/barcode/generate");

        $response->assertNotFound();
        $this->assertNull($product->refresh()->barcode);
    }

    public function test_owner_can_generate_a_barcode_for_a_variant_that_has_none(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $this->grantFeature($shop, 'product_variants');
        $shirt = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'price' => 650, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->patch("/app/product-variants/{$variant->id}/barcode/generate");

        $response->assertRedirect();
        $this->assertSame('ZV'.$variant->id, $variant->refresh()->barcode);
    }

    /** Generating is idempotent — calling it again on an already-barcoded variant must never silently overwrite a value a cashier may have already printed and stuck on stock. */
    public function test_generating_a_barcode_twice_keeps_the_first_one(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $this->grantFeature($shop, 'product_variants');
        $shirt = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'price' => 650, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'M', 'color' => 'Blue', 'barcode' => 'MANUALLY-SET', 'stock' => 10]);

        $this->actingAs($owner, 'web')->patch("/app/product-variants/{$variant->id}/barcode/generate");

        $this->assertSame('MANUALLY-SET', $variant->refresh()->barcode);
    }

    /** A shop's own tenant scope already protects this (see BelongsToTenant) — this just pins the 404, so a regression here isn't silent. */
    public function test_cannot_generate_a_barcode_for_another_shops_variant(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'barcode_printing');
        $this->grantFeature($shopB, 'product_variants');
        $shirt = Product::create(['shop_id' => $shopA->id, 'name' => 'Shirt', 'price' => 650, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shopA->id, 'product_id' => $shirt->id, 'size' => 'M', 'stock' => 10]);

        $response = $this->actingAs($ownerB, 'web')->patch("/app/product-variants/{$variant->id}/barcode/generate");

        $response->assertNotFound();
        $this->assertNull($variant->refresh()->barcode);
    }

    /** Scanning a variant's auto-generated barcode at the POS must resolve to that exact size/color, the same as a manually-set one already does. */
    public function test_pos_barcode_lookup_resolves_an_auto_generated_variant_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        $this->grantFeature($shop, 'product_variants');
        $shirt = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'price' => 650, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10]);
        $this->actingAs($owner, 'web')->patch("/app/product-variants/{$variant->id}/barcode/generate");
        $barcode = $variant->refresh()->barcode;

        $response = $this->actingAs($owner, 'web')->get("/app/pos/barcode/{$barcode}");

        $response->assertOk()->assertJson(['found' => true, 'variant_id' => $variant->id]);
    }
}
