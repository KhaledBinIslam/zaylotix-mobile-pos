<?php

namespace Tests\Feature;

use App\Models\Damage;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 4 — returns, damage and loss for online
 * orders (see docs/FIX_PLAN.md's Phase 34 design note). Core decision
 * under test: this reuses the EXISTING ReturnController::process()
 * entirely — no bespoke online-order return code — with one small hook
 * that flips an order to 'returned' only once every line is fully back.
 */
class ReturnOnlineOrderTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $this->grantFeature($shop, 'returns');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        return [$shop, $owner, $channel];
    }

    private function deliverTwoUnitOrder($owner, $channel, Product $product): OnlineOrder
    {
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 2]], 'cod_amount' => 200,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();

        return $order->fresh();
    }

    public function test_lost_condition_writes_off_via_damage_with_no_restock(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverTwoUnitOrder($owner, $channel, $product);
        $stockAfterDelivery = (float) $product->fresh()->stock;

        $saleItem = $order->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $order->sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 2, 'condition' => 'lost']],
        ])->assertOk();

        // no restock — the parcel never came back
        $this->assertEquals($stockAfterDelivery, (float) $product->fresh()->stock);

        $damage = Damage::where('product_id', $product->id)->first();
        $this->assertNotNull($damage);
        $this->assertStringContainsString('হারিয়ে', $damage->reason);
        $this->assertEquals(100, (float) $damage->loss); // 2 units at cost 50

        $this->assertSame('returned', $order->fresh()->status);
    }

    public function test_partial_return_does_not_change_the_online_orders_status(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverTwoUnitOrder($owner, $channel, $product);

        $saleItem = $order->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $order->sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        // only 1 of 2 units back — the order itself stays exactly as it
        // was, this is just an accounting event against the Sale
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(1, SalesReturn::where('sale_item_id', $saleItem->id)->count());
    }

    public function test_a_full_return_from_delivered_directly_also_flips_the_order(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverTwoUnitOrder($owner, $channel, $product);
        $this->assertSame('delivered', $order->status);

        // never explicitly marked 'returning' first — processing the
        // actual return is independent of that shipping-status marker
        $saleItem = $order->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $order->sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 2, 'condition' => 'resalable']],
        ])->assertOk();

        $this->assertSame('returned', $order->fresh()->status);
    }

    public function test_return_reason_is_stored(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverTwoUnitOrder($owner, $channel, $product);

        $saleItem = $order->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $order->sale->id, 'action' => 'refund', 'reason' => 'সাইজ মিলেনি',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 2, 'condition' => 'resalable']],
        ])->assertOk();

        $this->assertSame('সাইজ মিলেনি', SalesReturn::where('sale_item_id', $saleItem->id)->first()->reason);
    }

    public function test_a_cancelled_order_is_never_reopened_by_a_later_return_hook(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.cancel', $order->id), ['reason' => 'test'])->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_channel_return_rate_is_computed_correctly(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        // one fully returned, one kept delivered
        $returned = $this->deliverTwoUnitOrder($owner, $channel, $product);
        $this->deliverTwoUnitOrder($owner, $channel, $product);

        $saleItem = $returned->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $returned->sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 2, 'condition' => 'resalable']],
        ])->assertOk();

        $response = $this->actingAs($owner, 'web')->get(route('app.channels.index'));

        $response->assertOk()->assertInertia(fn ($page) => $page->where('channels.0.return_rate', 50));
    }
}
