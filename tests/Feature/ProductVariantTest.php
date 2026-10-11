<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A variant (size/color) has its own independent stock, unlike product_units
 * (a pack-size conversion sharing one stock pool) — products.stock is kept
 * as the live sum of all its variants' stock, so every existing report/
 * alert/valuation query that reads products.stock keeps working unchanged.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creating_a_variant_adds_its_stock_to_the_parent_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'M', 'color' => 'Red', 'stock' => 10,
        ])->assertRedirect();

        $this->assertEquals(10, $product->fresh()->stock);
        $this->assertSame(1, ProductVariant::count());
    }

    public function test_two_variants_sum_correctly_into_the_parent_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", ['size' => 'M', 'stock' => 10]);
        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", ['size' => 'L', 'stock' => 5]);

        $this->assertEquals(15, $product->fresh()->stock);
    }

    public function test_checkout_decrements_the_specific_variant_and_the_parent_together(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $large = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 5]);
        $product->update(['stock' => 15]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $medium->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 1200]],
        ]);

        $response->assertOk();
        $this->assertEquals(7, $medium->fresh()->stock); // 10 - 3
        $this->assertEquals(5, $large->fresh()->stock); // untouched
        $this->assertEquals(12, $product->fresh()->stock); // 15 - 3
        // $medium has no price of its own (null) — must fall back to the
        // parent product's ৳400, never bill at ৳0. The client never sends a
        // price at all (see CheckoutRequest — no items.*.price rule exists),
        // so this is purely a server-side computation to get right.
        $this->assertEquals(1200.0, (float) $response->json('sale.total')); // 400 * 3
        $this->assertEquals(400.0, (float) \App\Models\SaleItem::first()->price);
    }

    public function test_a_variant_priced_differently_than_the_parent_is_used_at_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $xl = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'XL', 'stock' => 5, 'price' => 450, 'cost' => 220]);
        $product->update(['stock' => 5]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $xl->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 450]],
        ]);

        $response->assertOk();
        $this->assertEquals(450.0, (float) $response->json('sale.total'));
    }

    public function test_a_product_with_variants_cannot_be_sold_without_specifying_one(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $product->update(['stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]], // no product_variant_id
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, $variant->fresh()->stock); // nothing moved
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_checkout_rejects_overselling_a_specific_variant_even_when_the_product_total_has_room(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 2]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 20]);
        $product->update(['stock' => 22]); // plenty in total, but only 2 in Medium

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $medium->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 2000]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(2, $medium->fresh()->stock);
    }

    public function test_voiding_a_variant_sale_restores_both_the_variant_and_the_parent_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $product->update(['stock' => 10]);

        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $medium->id, 'qty' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 1600]],
        ]);
        $checkout->assertOk();
        $this->assertEquals(6, $medium->fresh()->stock);
        $this->assertEquals(6, $product->fresh()->stock);

        $saleId = $checkout->json('sale.id');
        $this->actingAs($owner, 'web')->delete("/app/sales/{$saleId}", ['reason' => 'Wrong size selected'])
            ->assertRedirect();

        $this->assertEquals(10, $medium->fresh()->stock);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_deleting_a_variant_with_leftover_stock_removes_it_from_the_parent_total(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $medium = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);
        $large = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 5]);
        $product->update(['stock' => 15]);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$medium->id}")->assertRedirect();

        $this->assertEquals(5, $product->fresh()->stock); // 15 - 10
        $this->assertSoftDeleted('product_variants', ['id' => $medium->id]);
        $this->assertEquals(5, $large->fresh()->stock); // untouched
    }

    public function test_a_duplicate_size_color_combination_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Red', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'M', 'color' => 'Red', 'stock' => 5,
        ]);

        $response->assertSessionHasErrors('size');
        $this->assertSame(1, ProductVariant::count());
        $this->assertEquals(0, $product->fresh()->stock); // unchanged — the duplicate attempt never applied
    }

    public function test_variants_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'product_variants');
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        $variantB = ProductVariant::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'size' => 'M', 'stock' => 5]);

        // shop A's owner tries to check out against shop B's variant id
        $response = $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productA->id, 'product_variant_id' => $variantB->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 2]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(5, $variantB->fresh()->stock); // untouched
    }

    public function test_bulk_grid_creates_every_non_empty_cell_and_sums_into_parent_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [
                ['color' => 'Blue', 'size' => 'M', 'stock' => 12],
                ['color' => 'Blue', 'size' => 'L', 'stock' => 8],
                ['color' => 'Red', 'size' => 'M', 'stock' => 5],
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(3, ProductVariant::count());
        $this->assertEquals(25, $product->fresh()->stock);
    }

    public function test_bulk_grid_skips_cells_that_already_exist_without_erroring_the_whole_submit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [
                ['color' => 'Blue', 'size' => 'M', 'stock' => 99], // duplicate — skipped
                ['color' => 'Blue', 'size' => 'L', 'stock' => 8], // new
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(2, ProductVariant::count()); // one existing + one new, not three
        $this->assertEquals(18, $product->fresh()->stock); // 10 existing + 8 new only
    }

    /**
     * Regression test: PosController::barcode() used to only look up
     * Product::where('barcode', ...) — a variant's own barcode field
     * (settable per size/color) was never checked, so scanning a variant
     * sticker either matched nothing or, worse, matched the parent product
     * and let it be added with no variant chosen at all.
     */
    public function test_barcode_lookup_resolves_a_variants_own_barcode_and_flags_which_variant(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10, 'barcode' => 'VAR12345']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/VAR12345');

        $response->assertOk()->assertJson([
            'found' => true,
            'variant_id' => $variant->id,
        ]);
        $response->assertJsonPath('product.id', $product->id);
    }

    public function test_bulk_grid_rejects_a_weighed_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Loose Rice', 'cost' => 50, 'price' => 80, 'stock' => 0, 'sold_by_weight' => true]);

        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['color' => 'N/A', 'size' => 'N/A', 'stock' => 5]],
        ]);

        $response->assertSessionHasErrors('size');
        $this->assertSame(0, ProductVariant::count());
    }

    /**
     * The POS/Clothing variant pickers used to show "৳0" for a variant with
     * no price of its own (Number(null) !== Number(p.price) read as "this
     * variant has a different price") — a display bug only, confirmed by
     * the checkout tests above never billing at ৳0, but Khaled asked for a
     * read-only live audit regardless. This proves the command actually
     * finds a real ৳0 sale_item when one genuinely exists.
     */
    public function test_zero_price_sales_command_finds_a_genuinely_zero_priced_variant_sale(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 5]);
        $sale = \App\Models\Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 0, 'total' => 0, 'profit' => 0, 'payment_mode' => 'cash',
        ]);
        \App\Models\SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_name' => $product->name, 'variant_label' => 'M', 'unit_factor' => 1, 'qty' => 1, 'price' => 0, 'discount' => 0, 'cost' => 200,
        ]);

        $this->artisan('variants:zero-price-sales')
            ->expectsOutputToContain('INV-1')
            ->expectsOutputToContain('1টা লাইন')
            ->assertExitCode(0);
    }

    /**
     * Both this and the zero-price command above run outside any
     * authenticated request (no Tenancy::id()) — ProductVariant/Sale/etc.
     * all carry their own BelongsToTenant global scope, which a relation's
     * OWN internal query doesn't drop just because the outer query called
     * withoutGlobalScopes(). A first draft of this command used
     * Product::whereHas('variants')/with('variants') and silently found
     * nothing at all in that context; this pins the real detection.
     */
    public function test_stock_mismatch_command_finds_a_genuine_mismatch(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Desynced Shirt', 'cost' => 200, 'price' => 400, 'stock' => 99]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 5]);

        $this->artisan('variants:stock-mismatch')
            ->expectsOutputToContain('Desynced Shirt')
            ->expectsOutputToContain('1টা পণ্যে mismatch')
            ->assertExitCode(0);
    }

    /** Diagnostic for a mismatch variants:stock-mismatch finds — shows variants, transfers, purchases, and activity log for one product so Khaled can see what actually happened before deciding how (or whether) to reconcile it himself. */
    public function test_stock_history_command_shows_variants_transfers_and_purchases(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Investigated Shirt', 'cost' => 100, 'price' => 200, 'stock' => 40]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 28]);
        \App\Models\Purchase::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => null, 'qty' => 12, 'amount' => 1200, 'date' => now()->toDateString(), 'method' => 'cash', 'status' => 'paid']);

        $this->artisan('product:stock-history', ['product_id' => $product->id])
            ->expectsOutputToContain('Investigated Shirt')
            ->expectsOutputToContain('28')
            ->expectsOutputToContain('কোনো variant বলা হয়নি')
            ->assertExitCode(0);
    }
}
