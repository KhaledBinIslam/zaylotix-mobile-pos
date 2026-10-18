<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A quotation is a price quote given before the customer commits. Its own
 * store()/cancel() never touch stock or cash — only converting it into a
 * real sale does, and that conversion deliberately routes through the
 * exact same, already heavily-tested PosController::checkout() rather than
 * a second parallel money-moving code path (see the `quotation_id`
 * handling there). These tests focus on what's specific to quotations:
 * numbering, status transitions, and the conversion link — not re-testing
 * checkout's own stock/payment mechanics (see CheckoutTransactionTest).
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_create_a_quotation_with_multiple_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $a = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $b = Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 20, 'price' => 40, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/quotations', [
            'customer_name' => 'Rahim',
            'customer_phone' => '01911111111',
            'discount' => 10,
            'items' => [
                ['product_id' => $a->id, 'qty' => 2, 'price' => 100, 'discount' => 0],
                ['product_id' => $b->id, 'qty' => 3, 'price' => 40, 'discount' => 5],
            ],
        ]);

        $response->assertRedirect();
        $quotation = Quotation::first();
        $this->assertNotNull($quotation);
        $this->assertSame('QUO-1001', $quotation->quote_no); // shop's counter starts at 1000
        $this->assertEquals(200.0 + 115.0, (float) $quotation->subtotal); // (2*100) + (3*40-5)
        $this->assertEquals(10.0, (float) $quotation->discount);
        $this->assertSame(2, $quotation->items()->count());
    }

    public function test_quote_numbers_increment_sequentially_per_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post('/app/quotations', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 20]],
        ])->assertRedirect();
        $this->actingAs($owner, 'web')->post('/app/quotations', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 20]],
        ])->assertRedirect();

        $numbers = Quotation::orderBy('id')->pluck('quote_no')->all();
        $this->assertSame(['QUO-1001', 'QUO-1002'], $numbers);
    }

    public function test_owner_can_cancel_an_open_quotation(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 100, 'total' => 100]);

        $this->actingAs($owner, 'web')->post("/app/quotations/{$quotation->id}/cancel")->assertRedirect();

        $this->assertSame('cancelled', $quotation->fresh()->status);
    }

    public function test_a_converted_quotation_cannot_be_cancelled(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'converted', 'subtotal' => 100, 'total' => 100]);

        $response = $this->actingAs($owner, 'web')->post("/app/quotations/{$quotation->id}/cancel");

        $response->assertSessionHasErrors();
        $this->assertSame('converted', $quotation->fresh()->status);
    }

    public function test_pos_page_prefills_from_an_open_quotation(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);
        $quotation->items()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'A', 'qty' => 1, 'price' => 20, 'discount' => 0]);

        $response = $this->actingAs($owner, 'web')->get('/app/pos?quotation='.$quotation->id);

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Pos/Index')
            ->where('prefillQuotation.id', $quotation->id)
            ->has('prefillQuotation.items', 1)
        );
    }

    public function test_converting_a_quotation_marks_it_converted_and_links_the_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertOk();
        $quotation->refresh();
        $this->assertSame('converted', $quotation->status);
        $this->assertSame(Sale::first()->id, $quotation->sale_id);
        $this->assertEquals(9, $product->fresh()->stock); // stock genuinely moved via the normal checkout path
    }

    public function test_the_same_quotation_cannot_be_converted_twice(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Sale::count()); // only the first conversion produced a sale
        $this->assertEquals(9, $product->fresh()->stock); // second attempt moved nothing
    }

    public function test_converting_a_cancelled_quotation_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'cancelled', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_quotation_belonging_to_another_shop_cannot_be_converted(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'quotations');
        [$shopB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'quotations');
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotationB = Quotation::create(['shop_id' => $shopB->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($ownerA, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $productA->id, 'qty' => 1]],
            'quotation_id' => $quotationB->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertStatus(422);
        $this->assertSame('open', $quotationB->fresh()->status); // shop B's quote is untouched
    }

    /** Phase 0.4 — see docs/FIX_PLAN.md: a weighed product may be quoted at a fractional qty. */
    public function test_a_weighed_product_can_be_quoted_at_a_fractional_qty(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 80, 'stock' => 100, 'sold_by_weight' => true, 'weight_unit' => 'kg']);

        $response = $this->actingAs($owner, 'web')->post('/app/quotations', [
            'items' => [['product_id' => $rice->id, 'qty' => 1.5, 'price' => 80]],
        ]);

        $response->assertRedirect();
        $quotation = Quotation::first();
        $this->assertEquals(1.5, (float) $quotation->items()->first()->qty);
        $this->assertEquals(120.0, (float) $quotation->subtotal); // 1.5 * 80
    }

    public function test_a_non_weighed_product_still_rejects_a_fractional_qty_on_a_quotation(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/quotations', [
            'items' => [['product_id' => $product->id, 'qty' => 1.5, 'price' => 20]],
        ]);

        $response->assertSessionHasErrors('items');
        $this->assertSame(0, Quotation::count());
    }

    public function test_converting_via_checkout_requires_the_quotations_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // deliberately NOT granting 'quotations'
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    // ---- Phase 6: quotation strengthening (see docs/FIX_PLAN.md) ----

    public function test_an_expired_quotation_cannot_be_converted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->subDays(5)->toDateString(), 'valid_until' => now()->subDay()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertSame('open', $quotation->fresh()->status); // untouched, not silently converted
    }

    public function test_a_quotation_valid_through_today_can_still_be_converted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'valid_until' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();
    }

    /** Confirmed gap: nothing server-side enforced the quoted price — only a client-side discount the cashier could edit or bypass. */
    public function test_converting_a_quotation_never_charges_more_than_the_quoted_price_even_if_the_live_price_rose(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);
        $quotation->items()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'A', 'qty' => 1, 'price' => 20, 'discount' => 0]);
        $product->update(['price' => 35]); // live price rose after the quote was made

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);

        $response->assertOk();
        $this->assertEquals(20.0, (float) Sale::first()->total); // capped at the quoted price, not the new live 35
    }

    public function test_converting_a_quotation_passes_along_a_price_drop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);
        $quotation->items()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'A', 'qty' => 1, 'price' => 20, 'discount' => 0]);
        $product->update(['price' => 12]); // live price fell after the quote was made

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 12]],
        ]);

        $response->assertOk();
        $this->assertEquals(12.0, (float) Sale::first()->total); // benefits from the lower live price, not held to the old quote
    }

    public function test_a_quotations_own_line_discount_is_still_honored_at_conversion(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        // quoted at 20/unit with a 5 flat line discount -> net 15/unit
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 15, 'total' => 15]);
        $quotation->items()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'A', 'qty' => 1, 'price' => 20, 'discount' => 5]);
        $product->update(['price' => 30]); // live price rose well above even the quote's headline price

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'quotation_id' => $quotation->id,
            'payments' => [['method' => 'cash', 'amount' => 15]],
        ]);

        $response->assertOk();
        $this->assertEquals(15.0, (float) Sale::first()->total); // the quote's own discount is honored, not just its raw price
    }

    public function test_creating_a_quotation_beyond_current_stock_still_succeeds_with_a_warning(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Low Stock Item', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/quotations', [
            'items' => [['product_id' => $product->id, 'qty' => 50, 'price' => 20]],
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Quotation::count()); // still created — a quote isn't a stock reservation
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'পর্যাপ্ত স্টক নেই'));
    }

    public function test_owner_can_edit_an_open_quotation(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $a = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $b = Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 10, 'price' => 40, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);
        $quotation->items()->create(['shop_id' => $shop->id, 'product_id' => $a->id, 'product_name' => 'A', 'qty' => 1, 'price' => 20, 'discount' => 0]);

        $response = $this->actingAs($owner, 'web')->put("/app/quotations/{$quotation->id}", [
            'items' => [['product_id' => $b->id, 'qty' => 2, 'price' => 40]],
            'discount' => 10,
        ]);

        $response->assertRedirect();
        $quotation->refresh();
        $this->assertEquals(70.0, (float) $quotation->total); // (2*40) - 10
        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame($b->id, $quotation->items()->first()->product_id);
        $this->assertSame('QUO-1', $quotation->quote_no); // unchanged
    }

    public function test_editing_a_converted_quotation_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'quotations');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotation = Quotation::create(['shop_id' => $shop->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'converted', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($owner, 'web')->put("/app/quotations/{$quotation->id}", [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 20]],
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_editing_another_shops_quotation_is_blocked(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'quotations');
        [$shopB] = $this->createShopWithOwner();
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'B', 'cost' => 10, 'price' => 20, 'stock' => 10]);
        $quotationB = Quotation::create(['shop_id' => $shopB->id, 'quote_no' => 'QUO-1', 'date' => now()->toDateString(), 'status' => 'open', 'subtotal' => 20, 'total' => 20]);

        $response = $this->actingAs($ownerA, 'web')->put("/app/quotations/{$quotationB->id}", [
            'items' => [['product_id' => $productB->id, 'qty' => 1, 'price' => 20]],
        ]);

        $response->assertNotFound();
    }
}
