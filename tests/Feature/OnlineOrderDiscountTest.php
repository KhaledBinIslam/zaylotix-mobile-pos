<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled, 2026-11-26: scanning a barcode on the online order screen added the product, but there was no way to give
 * a discount, "same as POS". An order with a discount must record exactly what the customer pays — invoice total,
 * COD, due, profit — so the original bill can still be tracked.
 */
class OnlineOrderDiscountTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $this->grantFeature($shop, 'product_variants');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sneaker', 'cost' => 600, 'price' => 1000, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'কালো', 'stock' => 10, 'barcode' => 'SNK-40']);

        return [$shop, $owner, $channel, $product, $variant];
    }

    private function place($owner, SalesChannel $channel, Product $product, ProductVariant $variant, array $extra = [], array $line = [])
    {
        return $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), array_merge([
            'channel_id' => $channel->id, 'customer_phone' => '01811111111', 'customer_name' => 'Karim',
            'items' => [array_merge(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 2], $line)],
        ], $extra));
    }

    public function test_a_line_discount_lowers_the_bill_and_the_profit(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        $this->place($owner, $channel, $product, $variant, ['cod_amount' => 1800], ['discount' => 200])->assertRedirect();

        $sale = OnlineOrder::firstOrFail()->sale()->withVoided()->first();
        $this->assertEquals(1800, (float) $sale->subtotal, '2 x 1000 - 200');
        $this->assertEquals(1800, (float) $sale->total);
        $this->assertEquals(0, (float) $sale->discount, 'the overall discount is a separate figure');
        $this->assertEquals(600, (float) $sale->profit, '(1000-600) x 2 - 200');
        $line = $sale->items()->first();
        $this->assertEquals(200, (float) $line->discount, 'the line carries its own discount, as POS lines do');
        $this->assertEquals(1000, (float) $line->price, 'the price is untouched — the discount is not baked into it');
    }

    public function test_an_overall_order_discount_is_recorded_like_the_pos_manual_discount(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        $this->place($owner, $channel, $product, $variant, ['discount' => 150, 'delivery_charge' => 60, 'cod_amount' => 1910])->assertRedirect();

        $sale = OnlineOrder::firstOrFail()->sale()->withVoided()->first();
        $this->assertEquals(2000, (float) $sale->subtotal);
        $this->assertEquals(150, (float) $sale->discount);
        $this->assertEquals(1910, (float) $sale->total, '2000 - 150 + 60 delivery');
        $this->assertEquals(800 - 150, (float) $sale->profit, 'profit = (1000-600) x 2, less the order discount');
        $this->assertEquals(0, (float) $sale->customer->fresh()->due, 'COD covers the discounted total, nothing is left owing');
    }

    public function test_both_discounts_stack_and_the_due_follows_the_discounted_total(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        // 2000 - 200 (line) - 100 (order) = 1700, customer pays 500 in advance, rest is COD 1200
        $this->place($owner, $channel, $product, $variant, [
            'discount' => 100, 'paid_amount' => 500, 'paid_method' => 'cash', 'cod_amount' => 1000,
        ], ['discount' => 200])->assertRedirect();

        $sale = OnlineOrder::firstOrFail()->sale()->withVoided()->first();
        $this->assertEquals(1700, (float) $sale->total);
        $this->assertEquals(200, (float) $sale->customer->fresh()->due, '1700 - 500 paid - 1000 COD');
    }

    public function test_a_discount_never_exceeds_what_it_is_taken_from(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        $this->place($owner, $channel, $product, $variant, ['discount' => 999999], ['discount' => 999999])->assertRedirect();

        $sale = OnlineOrder::firstOrFail()->sale()->withVoided()->first();
        $this->assertEquals(0, (float) $sale->total, 'a typo cannot make the bill negative');
        $this->assertEquals(0, (float) $sale->subtotal);
        $this->assertGreaterThanOrEqual(0, (float) $sale->total);
    }

    public function test_an_order_without_any_discount_is_exactly_what_it_was_before(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        $this->place($owner, $channel, $product, $variant, ['cod_amount' => 2000])->assertRedirect();

        $sale = OnlineOrder::firstOrFail()->sale()->withVoided()->first();
        $this->assertEquals(2000, (float) $sale->total);
        $this->assertEquals(0, (float) $sale->discount);
        $this->assertEquals(800, (float) $sale->profit);
        $this->assertEquals(0, (float) $sale->items()->first()->discount);
    }

    public function test_stock_still_moves_by_quantity_not_by_value_when_discounted(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        $this->place($owner, $channel, $product, $variant, ['discount' => 500], ['discount' => 300])->assertRedirect();

        $this->assertEquals(8, (float) $variant->fresh()->stock);
    }

    public function test_a_negative_discount_is_rejected(): void
    {
        [, $owner, $channel, $product, $variant] = $this->setupShop();

        $this->place($owner, $channel, $product, $variant, ['discount' => -5])->assertSessionHasErrors('discount');
        $this->place($owner, $channel, $product, $variant, [], ['discount' => -5])->assertSessionHasErrors('items.0.discount');
        $this->assertSame(0, OnlineOrder::count());
    }
}
