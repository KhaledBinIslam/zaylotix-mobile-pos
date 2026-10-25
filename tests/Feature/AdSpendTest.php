<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalaryPayment;
use App\Models\SalesChannel;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Online Business" module, part 5 — ads and expense allocation (see
 * docs/FIX_PLAN.md's Phase 35 design note). Core decisions under test:
 * split math sums exactly to the total with the remainder on the last
 * row; `Expense::has('channelSplits')` (not a flag column) is what marks
 * ad spend; campaign ROAS only counts delivered-order revenue; overhead
 * allocation defaults to zero until the owner opts into a rule.
 */
class AdSpendTest extends TestCase
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

    public function test_percent_split_sums_exactly_to_total_with_remainder_on_last_channel(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $c = SalesChannel::create(['shop_id' => $shop->id, 'name' => 'Channel C', 'platform_type' => 'daraz', 'order_prefix' => 'C-']);

        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [
            'campaign_tag' => 'eid-sale', 'amount' => 10, 'method' => 'cash',
            'splits' => [
                ['channel_id' => $a->id, 'percent' => 33.33],
                ['channel_id' => $b->id, 'percent' => 33.33],
                ['channel_id' => $c->id, 'percent' => 33.34],
            ],
        ])->assertRedirect();

        $expense = Expense::where('campaign_tag', 'eid-sale')->first();
        $this->assertNotNull($expense);
        $amounts = $expense->channelSplits->pluck('amount', 'channel_id')->map(fn ($v) => (float) $v);

        $this->assertEquals(3.33, $amounts[$a->id]);
        $this->assertEquals(3.33, $amounts[$b->id]);
        // last row absorbs whatever rounding left over, not round(10*33.34/100)=3.33
        $this->assertEquals(3.34, $amounts[$c->id]);
        $this->assertEquals(10.0, $amounts->sum());
    }

    public function test_amount_mode_mismatch_is_rejected(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();

        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [
            'campaign_tag' => 'mismatch-test', 'amount' => 100, 'method' => 'cash',
            'splits' => [
                ['channel_id' => $a->id, 'amount' => 40],
                ['channel_id' => $b->id, 'amount' => 50],
            ],
        ])->assertStatus(422);

        $this->assertNull(Expense::where('campaign_tag', 'mismatch-test')->first());
    }

    public function test_usd_amount_converts_using_given_rate(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();

        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [
            'campaign_tag' => 'usd-campaign', 'amount_usd' => 10, 'exchange_rate' => 120, 'method' => 'cash',
            'splits' => [['channel_id' => $a->id, 'percent' => 100]],
        ])->assertRedirect();

        $expense = Expense::where('campaign_tag', 'usd-campaign')->first();
        $this->assertEquals(1200.0, (float) $expense->amount);
        $this->assertEquals(10.0, (float) $expense->amount_usd);
        $this->assertEquals(1200.0, (float) $expense->channelSplits->first()->amount);
    }

    public function test_cash_balance_decrements_by_the_bdt_total_exactly_once(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $before = (float) $shop->fresh()->cash_balance;

        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [
            'campaign_tag' => 'cash-test', 'amount' => 500, 'method' => 'cash',
            'splits' => [['channel_id' => $a->id, 'percent' => 100]],
        ])->assertRedirect();

        $this->assertEquals($before - 500, (float) $shop->fresh()->cash_balance);
    }

    public function test_ad_expense_without_splits_is_invisible_to_campaign_and_history(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();

        // a plain expense that happens to carry a campaign_tag but was
        // never processed through the split flow — must not be treated
        // as ad spend anywhere
        Expense::create(['shop_id' => $shop->id, 'campaign_tag' => 'not-really-ads', 'amount' => 300, 'method' => 'cash', 'date' => now()->toDateString()]);

        $response = $this->actingAs($owner, 'web')->get(route('app.ads.index'));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('adExpenses', [])
            ->where('campaigns', []));
    }

    public function test_campaign_roas_only_counts_delivered_orders_revenue(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 20]);

        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [
            'campaign_tag' => 'promo1', 'amount' => 200, 'method' => 'cash',
            'splits' => [['channel_id' => $a->id, 'percent' => 100]],
        ])->assertRedirect();

        // order 1: tagged, delivered — counts toward revenue
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $a->id, 'customer_phone' => '01811111111', 'campaign_tag' => 'promo1',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();
        $order1 = OnlineOrder::first();
        foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $status) {
            $this->actingAs($owner, 'web')->patch(route('app.onlineOrders.transition', $order1->id), ['status' => $status])->assertRedirect();
        }
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.deliver', $order1->id))->assertRedirect();

        // order 2: same tag, still pending — must NOT count toward revenue
        $this->actingAs($owner, 'web')->post(route('app.onlineOrders.store'), [
            'channel_id' => $a->id, 'customer_phone' => '01822222222', 'campaign_tag' => 'promo1',
            'items' => [['product_id' => $product->id, 'qty' => 1]], 'cod_amount' => 100,
        ])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->get(route('app.ads.index'));

        $response->assertOk()->assertInertia(function ($page) {
            $campaigns = $page->toArray()['props']['campaigns'];
            $this->assertCount(1, $campaigns);
            $this->assertSame('promo1', $campaigns[0]['campaign_tag']);
            $this->assertSame(2, $campaigns[0]['orders']);
            $this->assertSame(1, $campaigns[0]['delivered']);
            $this->assertEquals(100.0, $campaigns[0]['revenue']); // only the delivered order's sale total
        });
    }

    public function test_overhead_rule_equal_splits_total_across_every_channel(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        Expense::create(['shop_id' => $shop->id, 'amount' => 1000, 'method' => 'cash', 'date' => now()->toDateString()]); // plain overhead expense, no splits

        $this->actingAs($owner, 'web')->putJson(route('app.ads.overheadRule'), ['rule' => 'equal'])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->get(route('app.ads.index'));
        $response->assertOk()->assertInertia(function ($page) {
            $rows = $page->toArray()['props']['channelEconomics']['rows'];
            $this->assertCount(2, $rows);
            foreach ($rows as $row) {
                $this->assertEquals(500.0, $row['overhead_share']);
            }
        });
    }

    public function test_manual_rule_respects_each_channels_own_percent(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        Expense::create(['shop_id' => $shop->id, 'amount' => 1000, 'method' => 'cash', 'date' => now()->toDateString()]);

        $this->actingAs($owner, 'web')->putJson(route('app.ads.overheadRule'), [
            'rule' => 'manual',
            'percentages' => [
                ['channel_id' => $a->id, 'percent' => 70],
                ['channel_id' => $b->id, 'percent' => 30],
            ],
        ])->assertRedirect();

        $response = $this->actingAs($owner, 'web')->get(route('app.ads.index'));
        $response->assertOk()->assertInertia(function ($page) use ($a, $b) {
            $rows = collect($page->toArray()['props']['channelEconomics']['rows'])->keyBy('channel_id');
            $this->assertEquals(700.0, $rows[$a->id]['overhead_share']);
            $this->assertEquals(300.0, $rows[$b->id]['overhead_share']);
        });
    }

    public function test_a_shop_with_no_rule_set_allocates_zero_overhead(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        Expense::create(['shop_id' => $shop->id, 'amount' => 1000, 'method' => 'cash', 'date' => now()->toDateString()]);

        $response = $this->actingAs($owner, 'web')->get(route('app.ads.index'));
        $response->assertOk()->assertInertia(function ($page) {
            $rows = $page->toArray()['props']['channelEconomics']['rows'];
            foreach ($rows as $row) {
                $this->assertEquals(0.0, $row['overhead_share']);
                $this->assertEquals($row['profit_before_overhead'], $row['profit_after_overhead']);
            }
        });
    }

    public function test_payroll_feeds_total_overhead_without_being_re_entered(): void
    {
        [$shop, $owner, $a, $b] = $this->setupShop();
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Staff 1', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->toDateString(), 'status' => 'active']);
        SalaryPayment::create([
            'shop_id' => $shop->id, 'employee_id' => $employee->id, 'month' => now()->format('Y-m'), 'basic_salary' => 15000,
            'present_days' => 30, 'absent_days' => 0, 'attendance_deduction' => 0, 'bonus' => 0,
            'advance_deduction' => 0, 'net_paid' => 15000, 'method' => 'cash', 'paid_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($owner, 'web')->get(route('app.ads.index'));
        $response->assertOk()->assertInertia(fn ($page) => $page->where('channelEconomics.total_overhead', 15000));
    }

    public function test_a_shop_without_the_feature_gets_403(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted

        $this->actingAs($owner, 'web')->get(route('app.ads.index'))->assertStatus(403);
        $this->actingAs($owner, 'web')->postJson(route('app.ads.store'), [])->assertStatus(403);
    }
}
