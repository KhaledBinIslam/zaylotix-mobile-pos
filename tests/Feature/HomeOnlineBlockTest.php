<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 7 — see docs/FIX_PLAN.md's Phase 37
 * design note. Khaled's own much-earlier, separate ask: "home page e
 * online sells related kichu dekhay na" (the home page shows nothing
 * about online sales). Deliberately light — counts, not a profit
 * figure (see HomeController's own comment on why profit stays off
 * this screen); the full breakdown lives on the dedicated Online
 * Profit Report page (Part 6).
 */
class HomeOnlineBlockTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_online_block_is_absent_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['onboarded_at' => now()]);

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('onlineStats', null));
    }

    public function test_online_block_counts_in_progress_orders_and_todays_delivered_revenue(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $shop->update(['onboarded_at' => now()]);
        $this->grantFeature($shop, 'online_business');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);

        // order 1: delivered today — counts toward both delivered count and revenue
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01811111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order1 = OnlineOrder::first();
        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order1->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order1->id))->assertRedirect();

        // order 2: still pending — counts toward "in progress", not delivered
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '01822222222',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->get('/app/home');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('onlineStats.pendingCount', 1)
            ->where('onlineStats.deliveredTodayCount', 1)
            ->where('onlineStats.deliveredTodayRevenue', 100)
        );
    }
}
