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

    /**
     * The Salenoir bug: a product with pre-existing, un-sized stock (added
     * before variants existed) got its FIRST variant's stock just ADDED on
     * top instead of accounting for where the old stock went — products.stock
     * silently drifted away from SUM(variants.stock) forever. First-ever
     * variant creation on a product that already has stock must now account
     * for every pre-existing unit.
     */
    public function test_first_variant_on_a_product_with_pre_existing_stock_must_account_for_all_of_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 12]);

        // partial allocation (5 of the 12), no write-off flag — rejected
        $response = $this->actingAs($owner, 'web')->postJson("/app/products/{$product->id}/variants", [
            'size' => 'M', 'stock' => 5,
        ]);
        $response->assertStatus(422);
        $this->assertEquals(12, $product->fresh()->stock); // untouched
        $this->assertSame(0, ProductVariant::count()); // the whole request rolled back, not left half-done
    }

    public function test_first_variant_matching_the_pre_existing_stock_exactly_does_not_double_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 12]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'M', 'stock' => 12,
        ])->assertRedirect();

        $this->assertEquals(12, $product->fresh()->stock); // NOT 24 — the 12 was already this variant, not added again
        $this->assertEquals(12, ProductVariant::first()->stock);
    }

    public function test_first_variant_short_of_pre_existing_stock_can_write_off_the_rest_as_damage(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 12]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'size' => 'M', 'stock' => 9, 'write_off_rest' => true,
        ])->assertRedirect();

        $this->assertEquals(9, $product->fresh()->stock); // 12 - 3 written off
        $this->assertEquals(9, ProductVariant::first()->stock);
        $damage = \App\Models\Damage::first();
        $this->assertNotNull($damage);
        $this->assertEquals(3, (float) $damage->qty);
        $this->assertEquals(600.0, (float) $damage->loss); // 3 * cost(200)
    }

    public function test_bulk_grid_first_variants_must_sum_to_pre_existing_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 20]);

        $response = $this->actingAs($owner, 'web')->postJson("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['size' => 'M', 'stock' => 8], ['size' => 'L', 'stock' => 5]], // sums to 13, not 20
        ]);

        $response->assertStatus(422);
        $this->assertEquals(20, $product->fresh()->stock);
        $this->assertSame(0, ProductVariant::count());

        // same grid, but allocated exactly 20 total — accepted, product.stock unchanged
        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['size' => 'M', 'stock' => 8], ['size' => 'L', 'stock' => 12]],
        ])->assertRedirect();
        $this->assertEquals(20, $product->fresh()->stock);
    }

    public function test_allocating_more_than_the_pre_existing_stock_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson("/app/products/{$product->id}/variants", [
            'size' => 'M', 'stock' => 15, 'write_off_rest' => true, // write-off can't apply to an excess
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, $product->fresh()->stock);
    }

    /** Once a product's first variant has already established the invariant, a SECOND variant must go back to the normal purely-additive behavior — no reconciliation prompt for it. */
    public function test_a_second_variant_after_the_first_is_purely_additive_not_reconciled_again(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", ['size' => 'M', 'stock' => 10])->assertRedirect();

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", ['size' => 'L', 'stock' => 4])->assertRedirect();

        $this->assertEquals(14, $product->fresh()->stock); // 10 + 4, plain addition, no reconciliation needed
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
            // 'যোগফল: 28' (not bare '28'): the purchase row below also prints
            // today's date, e.g. "2026-10-28" — Laravel's test double matches
            // each printed console line against exactly one expected
            // substring and never retries a line against a later one, so a
            // bare '28' can "steal" that date-bearing line before the
            // Bengali assertion below ever gets a chance to match it.
            ->expectsOutputToContain('যোগফল: 28')
            ->expectsOutputToContain('কোনো variant বলা হয়নি')
            ->assertExitCode(0);
    }

    /** The Salenoir reconciliation tool — preview-only until --confirm, never touches products.stock, requires every variant named and the total to match. */
    public function test_set_stock_command_previews_then_only_writes_with_confirm(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'RP-Cyan-01', 'cost' => 100, 'price' => 200, 'stock' => 40]);
        $v40 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'stock' => 11]);
        $v41 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '41', 'stock' => 9]);
        $v42 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 8]);

        // preview only — nothing written
        $this->artisan('variants:set-stock', ['product_id' => $product->id, 'assignments' => ['40=15', '41=13', '42=12']])
            ->expectsOutputToContain('এটা শুধু প্রিভিউ')
            ->assertExitCode(0);
        $this->assertEquals(11, $v40->fresh()->stock);

        // sum doesn't match products.stock — rejected even with --confirm
        $this->artisan('variants:set-stock', ['product_id' => $product->id, 'assignments' => ['40=15', '41=13', '42=11'], '--confirm' => true])
            ->assertExitCode(1);
        $this->assertEquals(11, $v40->fresh()->stock);

        // a variant left out — rejected
        $this->artisan('variants:set-stock', ['product_id' => $product->id, 'assignments' => ['40=20', '41=20'], '--confirm' => true])
            ->assertExitCode(1);

        // confirmed, matching sum — writes, products.stock untouched
        $this->artisan('variants:set-stock', ['product_id' => $product->id, 'assignments' => ['40=15', '41=13', '42=12'], '--confirm' => true])
            ->expectsOutputToContain('লেখা হয়ে গেছে')
            ->assertExitCode(0);

        $this->assertEquals(15, $v40->fresh()->stock);
        $this->assertEquals(13, $v41->fresh()->stock);
        $this->assertEquals(12, $v42->fresh()->stock);
        $this->assertEquals(40, $product->fresh()->stock); // unchanged throughout
    }

    /**
     * Deleting a mis-entered article (Khaled, live: an 8-variant article
     * entered by mistake) used to leave its variants behind as live rows,
     * so a sticker printed for one of them still scanned — to a product
     * that no longer existed.
     */
    public function test_deleting_a_product_also_removes_its_variants(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'B-20007', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '37', 'color' => 'BROWN', 'stock' => 3, 'barcode' => 'SMS-B-20007-BRN-37']);

        $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}")->assertRedirect();

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        $this->assertSame(0, ProductVariant::count()); // gone from every normal query

        // and its sticker no longer resolves to anything
        $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/SMS-B-20007-BRN-37')
            ->assertStatus(404)->assertJson(['found' => false]);
    }

    public function test_restoring_a_product_brings_its_variants_back(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Red', 'stock' => 4]);

        $product->delete();
        $this->assertSame(0, ProductVariant::count());

        $product->restore();

        $this->assertSame(1, ProductVariant::count());
        $this->assertEquals(4, $variant->fresh()->stock);
    }

    public function test_scanning_a_variant_left_orphaned_by_an_older_delete_does_not_blow_up(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Old', 'cost' => 200, 'price' => 400, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Red', 'stock' => 4, 'barcode' => 'ORPHAN-1']);

        // exactly the state a pre-cascade delete left behind: product gone,
        // variant still live — a printed sticker for it still gets scanned.
        // Was a 500 (->variants() read off a null product), not a 404.
        $product->delete();
        ProductVariant::withTrashed()->whereKey($variant->id)->restore();

        $this->actingAs($owner, 'web')->getJson('/app/pos/barcode/ORPHAN-1')
            ->assertStatus(404)
            ->assertJson(['found' => false]);
    }
}
