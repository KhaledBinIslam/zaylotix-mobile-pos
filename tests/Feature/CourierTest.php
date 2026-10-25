<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Support\CourierCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 3 — couriers, shipments, COD settlement
 * (see docs/FIX_PLAN.md's Phase 33 design note). Core decisions under
 * test: courier_charge is an estimate computed once at assignment time;
 * settling COD is the one point it becomes real cash_balance, using the
 * actually-received amount, never the expected total.
 */
class CourierTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);

        return [$shop, $owner, $channel];
    }

    public function test_owner_can_create_a_courier_with_each_charge_type(): void
    {
        [$shop, $owner] = $this->setupShop();

        $this->actingAs($owner, 'web')->post(route('app.couriers.store'), [
            'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60],
        ])->assertRedirect();

        $courier = Courier::first();
        $this->assertSame('Pathao', $courier->name);
        $this->assertSame('flat', $courier->charge_type);
        $this->assertEquals(60, $courier->charge_config['amount']);
    }

    public function test_charge_estimate_flat(): void
    {
        $courier = new Courier(['charge_type' => 'flat', 'charge_config' => ['amount' => 70]]);
        $this->assertEquals(70, CourierCharge::estimate($courier));
    }

    public function test_charge_estimate_weight(): void
    {
        $courier = new Courier(['charge_type' => 'weight', 'charge_config' => ['base' => 60, 'per_kg' => 20]]);
        $this->assertEquals(60 + 20 * 2.5, CourierCharge::estimate($courier, 2.5));
    }

    public function test_charge_estimate_zone(): void
    {
        $courier = new Courier(['charge_type' => 'zone', 'charge_config' => ['inside_dhaka' => 60, 'outside_dhaka' => 120]]);
        $this->assertEquals(60, CourierCharge::estimate($courier, null, false));
        $this->assertEquals(120, CourierCharge::estimate($courier, null, true));
    }

    public function test_order_creation_defaults_to_channels_own_courier(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $channel->update(['default_courier_id' => $courier->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();

        $order = OnlineOrder::first();
        $this->assertSame($courier->id, $order->courier_id);
        $this->assertEquals(60, (float) $order->courier_charge);
    }

    public function test_explicit_courier_pick_overrides_channel_default(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $defaultCourier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $otherCourier = Courier::create(['shop_id' => $shop->id, 'name' => 'Steadfast', 'charge_type' => 'flat', 'charge_config' => ['amount' => 80]]);
        $channel->update(['default_courier_id' => $defaultCourier->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
            'courier_id' => $otherCourier->id,
        ])->assertRedirect();

        $order = OnlineOrder::first();
        $this->assertSame($otherCourier->id, $order->courier_id);
        $this->assertEquals(80, (float) $order->courier_charge);
    }

    public function test_courier_can_be_assigned_at_handed_to_courier_if_not_set_earlier(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'weight', 'charge_config' => ['base' => 50, 'per_kg' => 10]]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        $this->assertNull($order->courier_id);

        foreach (['confirmed', 'packed'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), [
            'status' => 'handed_to_courier', 'courier_id' => $courier->id, 'parcel_weight' => 2,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame($courier->id, $order->courier_id);
        $this->assertEquals(50 + 10 * 2, (float) $order->courier_charge);
        $this->assertEquals(2, (float) $order->parcel_weight);
    }

    private function deliverOrder($owner, OnlineOrder $order): void
    {
        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();
    }

    public function test_settling_cod_bumps_cash_balance_by_the_received_amount_not_expected(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100, 'courier_id' => $courier->id,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        $this->deliverOrder($owner, $order);

        $cashBefore = (float) $shop->fresh()->cash_balance;

        // courier actually paid 90, not the full 100 expected — the
        // shortfall must stay visible, never silently absorbed
        $this->actingAs($owner, 'web')->post(route('app.couriers.settle', $courier->id), [
            'order_ids' => [$order->id], 'received_amount' => 90,
        ])->assertRedirect();

        $this->assertEquals($cashBefore + 90, (float) $shop->fresh()->cash_balance);

        $settlement = CourierSettlement::first();
        $this->assertEquals(100, (float) $settlement->expected_amount);
        $this->assertEquals(90, (float) $settlement->received_amount);
        $this->assertEquals(-10, (float) $settlement->difference);

        $this->assertNotNull($order->fresh()->cod_settled_at);
        $this->assertSame($settlement->id, $order->fresh()->courier_settlement_id);
    }

    public function test_cannot_settle_an_order_twice(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100, 'courier_id' => $courier->id,
        ])->assertRedirect();
        $order = OnlineOrder::first();
        $this->deliverOrder($owner, $order);

        $this->actingAs($owner, 'web')->post(route('app.couriers.settle', $courier->id), ['order_ids' => [$order->id], 'received_amount' => 100])->assertRedirect();
        $cashAfterFirst = (float) $shop->fresh()->cash_balance;

        // a plain router.post with no useForm — a rejection surfaces via
        // the flash.error toast, not a crash (see docs/FIX_PLAN.md's
        // Phase 32 live-bug note)
        $this->actingAs($owner, 'web')->post(route('app.couriers.settle', $courier->id), ['order_ids' => [$order->id], 'received_amount' => 100])->assertRedirect();

        $this->assertEquals($cashAfterFirst, (float) $shop->fresh()->cash_balance);
        $this->assertSame(1, CourierSettlement::count());
    }

    public function test_pending_cod_shows_as_a_receivable_on_accounts(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $this->grantFeature($shop, 'accounts');
        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100, 'courier_id' => $courier->id,
        ])->assertRedirect();
        $order = OnlineOrder::first();

        // not delivered yet — courier hasn't collected anything, so
        // nothing is receivable
        $response = $this->actingAs($owner, 'web')->get('/app/accounts');
        $response->assertOk()->assertInertia(fn ($page) => $page->where('codReceivable', 0));

        $this->deliverOrder($owner, $order);

        $response = $this->actingAs($owner, 'web')->get('/app/accounts');
        $response->assertOk()->assertInertia(fn ($page) => $page->where('codReceivable', 100));
    }

    public function test_courier_analytics_computes_rates_correctly(): void
    {
        [$shop, $owner, $channel] = $this->setupShop();
        $courier = Courier::create(['shop_id' => $shop->id, 'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        // one delivered, one cancelled (never reaches the courier's count
        // the same way — still counted as a parcel since it was assigned)
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100, 'courier_id' => $courier->id,
        ])->assertRedirect();
        $delivered = OnlineOrder::first();
        $this->deliverOrder($owner, $delivered);

        $response = $this->actingAs($owner, 'web')->get(route('app.couriers.show', $courier->id));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.total', 1)
            ->where('stats.delivered', 1)
            ->where('stats.delivered_pct', 100)
            ->where('stats.cod_pending', 100)
        );
    }

    public function test_owner_cannot_touch_another_shops_courier(): void
    {
        [$shopA, $ownerA] = $this->setupShop();
        [$shopB] = $this->setupShop();
        $otherCourier = Courier::create(['shop_id' => $shopB->id, 'name' => 'Foreign Courier', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60]]);

        $this->actingAs($ownerA, 'web')->put(route('app.couriers.update', $otherCourier->id), [
            'name' => 'Hijacked', 'charge_type' => 'flat', 'charge_config' => ['amount' => 1],
        ])->assertNotFound();
    }

    public function test_courier_management_is_blocked_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted

        $this->actingAs($owner, 'web')->post(route('app.couriers.store'), [
            'name' => 'Pathao', 'charge_type' => 'flat', 'charge_config' => ['amount' => 60],
        ])->assertStatus(403);
    }
}
