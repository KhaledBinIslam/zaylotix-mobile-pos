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
}
