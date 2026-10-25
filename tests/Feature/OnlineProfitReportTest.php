<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseChannelSplit;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Support\OnlineProfitReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 6 — the profit engine and reports (see
 * docs/FIX_PLAN.md's Phase 36 design note). Core decisions under test:
 * View A's "online" totals are the literal sum of View B's own rows (not
 * a second formula that could drift); delivery_charge/courier_charge now
 * actually move net profit, where the pre-existing P&L collapsed them to
 * zero; allocated + unallocated overhead always equals the total pool,
 * even when manual percentages don't sum to 100%.
 */
class OnlineProfitReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $a = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Channel A', 'platform_type' => 'facebook', 'order_prefix' => 'A-']);
        $b = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Channel B', 'platform_type' => 'website', 'order_prefix' => 'B-']);

        return [$shop, $owner, $a, $b];
    }

    /** Places + delivers a 1-unit order on the given channel, with the given delivery/courier charges, inside [from, to]. */
    private function deliverOrder($owner, SalesChannel $channel, Product $product, float $deliveryCharge = 0, float $courierCharge = 0, ?string $deliveredOn = null): OnlineOrder
    {
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $channel->id, 'customer_phone' => '018'.random_int(10000000, 99999999),
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'delivery_charge' => $deliveryCharge, 'cod_amount' => 100 + $deliveryCharge,
        ])->assertRedirect();
        $order = OnlineOrder::latest('id')->first();

        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();

        // query-builder update, not $model->update() — bypasses mass-assignment
        // guarding so a test can set fields (like created_at below) that aren't
        // meant to be fillable through any real HTTP request
        OnlineOrder::withoutGlobalScopes()->whereKey($order->id)
            ->update(['courier_charge' => $courierCharge] + ($deliveredOn ? ['delivered_at' => $deliveredOn.' 12:00:00'] : []));

        return $order->fresh();
    }

    public function test_consolidated_online_totals_equal_the_sum_of_channel_rows(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);

        $this->deliverOrder($owner, $a, $product, 20, 30);
        $this->deliverOrder($owner, $b, $product, 15, 25);

        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [
            'campaign_tag' => 'x', 'amount' => 200, 'method' => 'cash',
            'splits' => [['channel_id' => $a->id, 'percent' => 100]],
        ])->assertRedirect();

        foreach (['equal', 'revenue_share'] as $rule) {
            $this->actingAs($owner, 'web')->putJson(route('app.ads.overheadRule'), ['rule' => $rule])->assertRedirect();

            $from = now()->startOfMonth()->toDateString();
            $to = now()->toDateString();
            $consolidated = OnlineProfitReport::consolidated($from, $to);
            $rows = collect(OnlineProfitReport::byChannel($from, $to));

            foreach (['revenue', 'cogs', 'courier_cost', 'ad_spend', 'platform_gateway_fees', 'damage_loss', 'gross_profit', 'net_profit'] as $field) {
                $this->assertEquals(
                    $consolidated['online'][$field],
                    round((float) $rows->sum($field), 2),
                    "mismatch on {$field} under rule {$rule}"
                );
            }
        }
    }

    public function test_manual_rule_with_mismatched_percentages_still_balances_allocated_plus_unallocated(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $this->deliverOrder($owner, $a, $product);
        $this->deliverOrder($owner, $b, $product);

        Expense::create(['shop_id' => $shop->id, 'amount' => 1000, 'method' => 'cash', 'date' => now()->toDateString()]);

        // percentages intentionally sum to only 60%, not 100 — Part 5's own
        // documented freedom ("does not need to total 100%")
        $this->actingAs($owner, 'web')->putJson(route('app.ads.overheadRule'), [
            'rule' => 'manual',
            'percentages' => [
                ['channel_id' => $a->id, 'percent' => 40],
                ['channel_id' => $b->id, 'percent' => 20],
            ],
        ])->assertRedirect();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $consolidated = OnlineProfitReport::consolidated($from, $to);

        $this->assertEquals(1000.0, $consolidated['total_overhead_pool']);
        $this->assertEquals(600.0, $consolidated['online']['overhead']); // 40%+20% of 1000
        $this->assertEquals(400.0, $consolidated['unallocated_overhead']);
    }

    public function test_delivery_charge_and_courier_charge_actually_move_net_profit(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $order = $this->deliverOrder($owner, $a, $product, deliveryCharge: 50, courierCharge: 80);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $row = collect(OnlineProfitReport::byChannel($from, $to))->firstWhere('channel_id', $a->id);

        // product margin alone would be 100-50=50; + delivery(50) - courier(80) = 20
        $this->assertEquals(50.0, (float) $order->sale->profit);
        $this->assertEquals(20.0, $row['gross_profit']);
    }

    public function test_platform_and_gateway_fees_reduce_profit_by_the_configured_percent(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'online_business');
        $channel = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Fee Channel', 'platform_type' => 'daraz', 'order_prefix' => 'D-', 'platform_fee_percent' => 5, 'gateway_fee_percent' => 2]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);

        $order = $this->deliverOrder($owner, $channel, $product);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $row = collect(OnlineProfitReport::byChannel($from, $to))->firstWhere('channel_id', $channel->id);

        $this->assertEquals(100.0, $row['revenue']);
        $this->assertEquals(7.0, $row['platform_gateway_fees']); // 7% of 100
        $this->assertEquals(43.0, $row['gross_profit']); // 50 margin - 7 fees
    }

    public function test_a_return_reduces_the_report_without_mutating_the_underlying_sale(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $order = $this->deliverOrder($owner, $a, $product);
        $originalTotal = (float) $order->sale->total;
        $originalProfit = (float) $order->sale->profit;

        $saleItem = $order->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $order->sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $this->assertEquals($originalTotal, (float) $order->sale->fresh()->total);
        $this->assertEquals($originalProfit, (float) $order->sale->fresh()->profit);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $row = collect(OnlineProfitReport::byChannel($from, $to))->firstWhere('channel_id', $a->id);
        $this->assertEquals(0.0, $row['revenue']); // fully refunded
    }

    public function test_damage_loss_from_a_lost_return_reduces_profit_by_exactly_the_sales_return_cost(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $order = $this->deliverOrder($owner, $a, $product);

        $saleItem = $order->sale->items->first();
        $this->actingAs($owner, 'web')->postJson(route('app.returns.process'), [
            'sale_id' => $order->sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $saleItem->id, 'qty' => 1, 'condition' => 'lost']],
        ])->assertOk();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $row = collect(OnlineProfitReport::byChannel($from, $to))->firstWhere('channel_id', $a->id);
        $this->assertEquals(50.0, $row['damage_loss']); // cost of the 1 lost unit
    }

    public function test_operational_and_financial_cohorts_can_legitimately_diverge(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);
        $order = $this->deliverOrder($owner, $a, $product);

        // placed "last month" but delivered "this month" — query-builder
        // update bypasses mass-assignment guarding (created_at isn't fillable)
        OnlineOrder::withoutGlobalScopes()->whereKey($order->id)
            ->update(['created_at' => now()->subMonth()->startOfMonth()->addDay()]);

        $lastMonthFrom = now()->subMonth()->startOfMonth()->toDateString();
        $lastMonthTo = now()->subMonth()->endOfMonth()->toDateString();
        $thisMonthFrom = now()->startOfMonth()->toDateString();
        $thisMonthTo = now()->toDateString();

        $lastMonthRow = collect(OnlineProfitReport::byChannel($lastMonthFrom, $lastMonthTo))->firstWhere('channel_id', $a->id);
        $thisMonthRow = collect(OnlineProfitReport::byChannel($thisMonthFrom, $thisMonthTo))->firstWhere('channel_id', $a->id);

        $this->assertSame(1, $lastMonthRow['orders_placed']);
        $this->assertSame(0, $lastMonthRow['delivered_count']); // delivered this month, not last

        $this->assertSame(0, $thisMonthRow['orders_placed']); // placed last month, not this one
        $this->assertSame(1, $thisMonthRow['delivered_count']);
    }

    public function test_a_shop_without_the_feature_gets_403(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get(route('app.onlineProfit.index'))->assertStatus(403);
    }
}
