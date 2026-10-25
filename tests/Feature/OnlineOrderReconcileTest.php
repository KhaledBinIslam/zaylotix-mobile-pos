<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Notifications\OnlineReconcileMismatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 7 — see docs/FIX_PLAN.md's Phase 37
 * design note. Covers online:reconcile's two checkable invariants:
 * counted_in_reports must track delivered_at exactly, and a COD
 * settlement's expected_amount/cod_settled_at/courier_settlement_id
 * must stay internally consistent.
 */
class OnlineOrderReconcileTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        return [$shop, $owner, $channel];
    }

    private function deliverOrder($owner, SalesChannel $channel, Product $product): OnlineOrder
    {
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::latest('id')->first();

        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();

        return $order->fresh();
    }

    public function test_passes_clean_when_nothing_is_wrong(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->deliverOrder($owner, $channel, $product);

        $this->artisan('online:reconcile')->assertExitCode(0);
    }

    public function test_flags_a_delivered_order_whose_sale_was_never_counted(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverOrder($owner, $channel, $product);

        // simulate the exact bug this check exists to catch — revenue
        // silently never flipped visible despite real delivery
        Sale::withoutGlobalScopes()->whereKey($order->sale_id)->update(['counted_in_reports' => false]);

        $this->artisan('online:reconcile')->assertExitCode(1);
    }

    public function test_flags_a_pending_order_whose_sale_was_counted_too_early(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        Sale::withoutGlobalScopes()->whereKey($order->sale_id)->update(['counted_in_reports' => true]);

        $this->artisan('online:reconcile')->assertExitCode(1);
    }

    public function test_flags_cod_settled_at_set_without_a_settlement_id(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverOrder($owner, $channel, $product);

        OnlineOrder::withoutGlobalScopes()->whereKey($order->id)->update(['cod_settled_at' => now()]);

        $this->artisan('online:reconcile')->assertExitCode(1);
    }

    public function test_flags_a_settlement_whose_expected_amount_does_not_match_its_orders(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverOrder($owner, $channel, $product);

        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $settlement = CourierSettlement::create([
            'shop_id' => $shop->id, 'courier_id' => $courier->id, 'expected_amount' => 100, 'received_amount' => 100,
            'difference' => 0, 'settled_by' => $owner->id, 'settled_at' => now(),
        ]);
        OnlineOrder::withoutGlobalScopes()->whereKey($order->id)->update(['cod_settled_at' => now(), 'courier_settlement_id' => $settlement->id]);

        // corrupt the settlement's own expected_amount after the fact
        CourierSettlement::withoutGlobalScopes()->whereKey($settlement->id)->update(['expected_amount' => 500]);

        $this->artisan('online:reconcile')->assertExitCode(1);
    }

    public function test_scoped_to_one_shop_when_given(): void
    {
        [$shopA, $ownerA, $channelA] = $this->setupShop();
        [$shopB, $ownerB, $channelB] = $this->setupShop();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $cleanOrder = $this->deliverOrder($ownerA, $channelA, $productA);
        $brokenOrder = $this->deliverOrder($ownerB, $channelB, $productB);
        Sale::withoutGlobalScopes()->whereKey($brokenOrder->sale_id)->update(['counted_in_reports' => false]);

        $this->artisan('online:reconcile', ['--shop' => $shopA->id])->assertExitCode(0);
        $this->artisan('online:reconcile', ['--shop' => $shopB->id])->assertExitCode(1);
    }

    public function test_notify_sends_the_owner_one_notification_per_day(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $order = $this->deliverOrder($owner, $channel, $product);
        Sale::withoutGlobalScopes()->whereKey($order->sale_id)->update(['counted_in_reports' => false]);

        $this->artisan('online:reconcile', ['--notify' => true])->assertExitCode(1);
        $this->artisan('online:reconcile', ['--notify' => true])->assertExitCode(1);

        $this->assertSame(1, $owner->fresh()->notifications()->where('type', OnlineReconcileMismatch::class)->count());
    }
}
