<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The strip above the online order list — Khaled: "koita sell hocche,
 * koita return, status wise dashboard-e dekhao". Status counts cover the
 * whole shop; the money figures cover orders created in the chosen
 * period; nothing from another shop ever leaks in.
 */
class OnlineOrdersDashboardTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function shopWithOrders(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 100]);

        return [$shop, $owner, $channel, $product];
    }

    /** one real order through the store route (so a Sale with a total exists), then its status set directly */
    private function order($owner, $channel, $product, int $qty, string $status, array $extra = []): OnlineOrder
    {
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '0181'.random_int(1000000, 9999999), 'customer_name' => 'Karim',
            'items' => [['product_id' => $product->id, 'qty' => $qty]],
            'cod_amount' => $qty * 100,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = OnlineOrder::withoutGlobalScopes()->orderByDesc('id')->first();
        DB::table('online_orders')->where('id', $order->id)->update(['status' => $status] + $extra);

        return $order->fresh();
    }

    public function test_status_counts_and_period_money_figures_are_right_and_shop_scoped(): void
    {
        [$shop, $owner, $channel, $product] = $this->shopWithOrders();

        $this->order($owner, $channel, $product, 2, 'delivered', ['delivered_at' => now()]);       // 200, COD 200 unsettled
        $this->order($owner, $channel, $product, 3, 'delivered', ['delivered_at' => now(), 'cod_settled_at' => now()]); // 300, settled
        $this->order($owner, $channel, $product, 1, 'returned');                                     // 100
        $this->order($owner, $channel, $product, 4, 'returning');                                    // 400
        $this->order($owner, $channel, $product, 1, 'cancelled', ['cancelled_at' => now()]);
        $this->order($owner, $channel, $product, 1, 'pending');
        $this->order($owner, $channel, $product, 1, 'packed');
        $this->order($owner, $channel, $product, 1, 'in_transit');
        $this->order($owner, $channel, $product, 1, 'failed');
        // an old delivered order — in the all-time status counts, outside "today"
        $this->order($owner, $channel, $product, 5, 'delivered', ['created_at' => now()->subDays(40), 'delivered_at' => now()->subDays(40)]);

        // another shop's orders must never be counted
        [$otherShop, $otherOwner] = $this->createShopWithOwner();
        $this->grantFeature($otherShop, 'online_business');
        $otherChannel = SalesChannel::create(['shop_id' => $otherShop->id, 'name' => 'Page B', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $otherProduct = Product::create(['shop_id' => $otherShop->id, 'name' => 'Oil', 'cost' => 50, 'price' => 1000, 'stock' => 100]);
        $this->order($otherOwner, $otherChannel, $otherProduct, 9, 'delivered', ['delivered_at' => now()]);

        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.index', ['period' => 'today']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'today')
                ->where('dashboard.status_counts.delivered', 3)   // whole shop, old one included
                ->where('dashboard.status_counts.returned', 1)
                ->where('dashboard.status_counts.returning', 1)
                ->where('dashboard.status_counts.cancelled', 1)
                ->where('dashboard.status_counts.pending', 1)
                ->where('dashboard.status_counts.packed', 1)
                ->where('dashboard.status_counts.in_transit', 1)
                ->where('dashboard.status_counts.failed', 1)
                ->where('dashboard.status_counts.confirmed', 0)
                ->where('dashboard.period.orders', 9)             // today: everything but the 40-day-old one
                ->where('dashboard.period.delivered_count', 2)
                ->where('dashboard.period.delivered_total', fn ($v) => (float) $v === 500.0)
                ->where('dashboard.period.returned_count', 2)
                ->where('dashboard.period.returned_total', fn ($v) => (float) $v === 500.0)
                ->where('dashboard.period.cancelled_count', 1)
                ->where('dashboard.period.failed_count', 1)
                ->where('dashboard.period.awaiting_count', 2)     // pending + packed
                ->where('dashboard.period.on_the_way_count', 1)   // in_transit
                ->where('dashboard.cod_pending', fn ($v) => (float) $v === 700.0) // all-time, delivered & unsettled: 200 today + 500 (40-day-old), this shop only
            );

        // "month" takes the old order in too (it is 40 days back -> still outside this month)
        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.index', ['period' => 'month']))
            ->assertInertia(fn ($page) => $page->where('dashboard.period.delivered_count', 2)->where('period', 'month'));
    }

    public function test_the_status_chip_filter_narrows_the_list_but_not_the_counts(): void
    {
        [, $owner, $channel, $product] = $this->shopWithOrders();
        $this->order($owner, $channel, $product, 1, 'delivered', ['delivered_at' => now()]);
        $this->order($owner, $channel, $product, 1, 'pending');
        $this->order($owner, $channel, $product, 1, 'pending');

        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.index', ['status' => 'pending']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', 'pending')
                ->has('orders.data', 2)
                ->where('dashboard.status_counts.pending', 2)
                ->where('dashboard.status_counts.delivered', 1));

        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.index'))
            ->assertInertia(fn ($page) => $page->has('orders.data', 3)->where('period', 'month'));
    }

    public function test_an_unknown_period_falls_back_to_this_month(): void
    {
        [, $owner] = $this->shopWithOrders();

        $this->actingAs($owner, 'web')->get(route('app.onlineOrders.index', ['period' => 'whatever']))
            ->assertOk()->assertInertia(fn ($page) => $page->where('period', 'month')->where('dashboard.period.orders', 0));
    }
}
