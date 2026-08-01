<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The returns form doesn't ask which invoice an item came from (most shops
 * here don't keep receipts), so the only guard against a fabricated or
 * duplicated return is a lifetime ceiling: qty returned can never exceed
 * qty ever actually sold (across all sale-item unit factors), minus what's
 * already been returned against it.
 */
class ReturnTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function recordSale(Shop $shop, Product $product, int $qty): void
    {
        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => $qty * 20, 'total' => $qty * 20, 'payment_mode' => 'cash',
        ]);
        SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Soap',
            'unit_factor' => 1, 'qty' => $qty, 'price' => 20, 'discount' => 0, 'cost' => 10,
        ]);
    }

    public function test_owner_can_return_a_quantity_that_was_actually_sold(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $this->recordSale($shop, $product, 10);

        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id,
            'qty' => 3,
            'refund' => 60,
        ]);

        $response->assertRedirect();
        $this->assertEquals(8, $product->fresh()->stock); // 5 + 3
    }

    public function test_cannot_return_more_than_was_ever_sold(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $this->recordSale($shop, $product, 2);

        // never sold this much — a fabricated/duplicated return
        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id,
            'qty' => 10,
            'refund' => 200,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(5, $product->fresh()->stock); // unchanged
    }

    public function test_cannot_return_a_product_that_was_never_sold_at_all(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id,
            'qty' => 1,
            'refund' => 20,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(5, $product->fresh()->stock);
    }

    /**
     * Regression test: a voided sale's line items must not count toward
     * "ever sold" — SaleReversal already gave that stock back once when the
     * sale was voided, so counting it here too would let the same units be
     * "returned" a second time for a second stock credit and cash refund.
     */
    public function test_a_voided_sales_items_do_not_count_toward_returnable_qty(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $this->recordSale($shop, $product, 5);
        // Sale's global tenant scope needs a resolved tenant, which only
        // exists inside a request handled by the `shop` middleware — this
        // is a plain PHP context, so query around the scope instead
        $sale = Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->first();

        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", [
            'reason' => 'Cancelled, customer changed their mind',
        ])->assertRedirect();

        // the 5 units are back in stock via the void's own reversal — a
        // return against this product must now be rejected entirely, not
        // allowed up to 5 again
        $response = $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 1, 'refund' => 20,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(10, $product->fresh()->stock); // 5 (start) + 5 (void reversal), not +1 more
    }

    public function test_repeated_returns_cannot_exceed_the_combined_sold_quantity(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $this->recordSale($shop, $product, 5);

        $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 5, 'refund' => 100,
        ])->assertRedirect();

        // already returned all 5 that were ever sold — a second return of
        // any amount must now be rejected
        $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'qty' => 1, 'refund' => 20,
        ])->assertSessionHasErrors('qty');
    }
}
