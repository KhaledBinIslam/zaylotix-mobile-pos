<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesChannel;
use App\Models\Shop;
use App\Models\User;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled, 2026-11-26: "account e offline sales profit, online sales profit, total profit, product wise profit".
 * The one rule that makes the three numbers trustworthy: offline + online == the P&L's own gross profit, whatever
 * mix of discounts, returns, voided bills and not-yet-delivered orders the period holds.
 */
class ProfitByChannelTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private SalesChannel $channel;

    private Product $shoe;     // cost 600 / price 1000

    private Product $cap;      // cost 100 / price 200

    protected function setUp(): void
    {
        parent::setUp();
        [$this->shop, $this->owner] = $this->createShopWithOwner(['cash_balance' => 10000]);
        foreach (['accounts', 'returns', 'online_business'] as $f) {
            $this->grantFeature($this->shop, $f);
        }
        $this->channel = SalesChannel::create(['shop_id' => $this->shop->id, 'name' => 'Page A', 'platform_type' => 'facebook', 'order_prefix' => 'FB-']);
        $this->shoe = Product::create(['shop_id' => $this->shop->id, 'name' => 'Shoe', 'cost' => 600, 'price' => 1000, 'stock' => 100]);
        $this->cap = Product::create(['shop_id' => $this->shop->id, 'name' => 'Cap', 'cost' => 100, 'price' => 200, 'stock' => 100]);
    }

    private function as(): static
    {
        Tenancy::clear();
        Tenancy::forgetShopCache();

        return $this->actingAs($this->owner, 'web');
    }

    private function counterSale(array $items, array $extra = []): Sale
    {
        $total = collect($items)->sum(fn ($i) => (($i['product_id'] === $this->shoe->id) ? 1000 : 200) * $i['qty'] - ($i['discount'] ?? 0)) - ($extra['discount'] ?? 0);
        $r = $this->as()->postJson('/app/pos/checkout', array_merge(['items' => $items, 'payments' => [['method' => 'cash', 'amount' => $total]]], $extra));
        $this->assertSame(200, $r->status(), substr((string) $r->getContent(), 0, 300));

        return Sale::withVoided()->findOrFail($r->json('sale.id'));
    }

    private function onlineOrder(array $items, array $extra = [], bool $deliver = true): OnlineOrder
    {
        $this->as()->post(route('app.onlineOrders.store'), array_merge([
            'channel_id' => $this->channel->id, 'customer_phone' => '018'.random_int(10000000, 99999999), 'items' => $items,
        ], $extra))->assertRedirect();
        $order = OnlineOrder::latest('id')->firstOrFail();
        if ($deliver) {
            foreach (['confirmed', 'packed', 'handed_to_courier', 'in_transit'] as $s) {
                $this->as()->patch(route('app.onlineOrders.transition', $order->id), ['status' => $s])->assertRedirect();
            }
            $this->as()->post(route('app.onlineOrders.deliver', $order->id))->assertRedirect();
        }

        return $order->fresh();
    }

    private function range(): array
    {
        return [now()->toDateString(), now()->toDateString()];
    }

    public function test_offline_plus_online_always_equals_the_pl_gross_profit(): void
    {
        // counter: 2 shoes with ৳100 off one line and ৳50 off the whole bill; plus a plain cap sale
        $this->counterSale([['product_id' => $this->shoe->id, 'qty' => 2, 'discount' => 100], ['product_id' => $this->cap->id, 'qty' => 1]], ['discount' => 50]);
        $this->counterSale([['product_id' => $this->cap->id, 'qty' => 3]]);
        // online: 1 shoe with ৳80 off, delivery ৳60 (delivered)
        $this->onlineOrder([['product_id' => $this->shoe->id, 'qty' => 1, 'discount' => 80]], ['delivery_charge' => 60, 'cod_amount' => 980]);
        // online, NOT delivered yet — must not count anywhere
        $this->onlineOrder([['product_id' => $this->cap->id, 'qty' => 5]], ['cod_amount' => 1000], false);

        [$from, $to] = $this->range();
        $split = Reports::profitByChannel($from, $to);
        $pl = Reports::rangeStats($from, $to);

        $this->assertEqualsWithDelta($pl['grossProfit'], $split['total']['profit'], 0.01, 'the three numbers agree with the P&L');
        $this->assertEqualsWithDelta($split['offline']['profit'] + $split['online']['profit'], $split['total']['profit'], 0.01);
        // counter: shoe (1000-600)*2 - 100 = 700 ; cap 100 ; second sale 3*100 = 300 ; minus ৳50 bill discount = 1050
        $this->assertEqualsWithDelta(1050, $split['offline']['profit'], 0.01);
        // online: (1000-600) - 80 = 320 (delivery charge is not profit)
        $this->assertEqualsWithDelta(320, $split['online']['profit'], 0.01);
        $this->assertSame(1, $split['online']['bills'], 'the undelivered order is not counted');
        $this->assertSame(2, $split['offline']['bills']);
    }

    public function test_the_identity_survives_returns_on_both_channels(): void
    {
        $counter = $this->counterSale([['product_id' => $this->shoe->id, 'qty' => 2]]);
        $order = $this->onlineOrder([['product_id' => $this->shoe->id, 'qty' => 2, 'discount' => 100]], ['cod_amount' => 1900]);

        foreach ([$counter, $order->sale()->withVoided()->first()] as $sale) {
            $item = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->firstOrFail();
            $this->as()->postJson('/app/returns/process', ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'size', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']]])->assertOk();
        }

        [$from, $to] = $this->range();
        $split = Reports::profitByChannel($from, $to);
        $pl = Reports::rangeStats($from, $to);

        $this->assertEqualsWithDelta($pl['grossProfit'], $split['total']['profit'], 0.01);
        // each channel loses the profit of the ONE pc handed back, not the other channel's
        $this->assertEqualsWithDelta(400, $split['offline']['profit'], 0.01, '(1000-600)*2 less the returned pc 400');
        $this->assertEqualsWithDelta(350, $split['online']['profit'], 0.01, '((1000-600)*2 - 100) less the returned pc 350');
        $this->assertGreaterThan(0, $split['online']['returns']);
        $this->assertGreaterThan(0, $split['offline']['returns']);
    }

    public function test_a_voided_counter_bill_is_not_counted(): void
    {
        $keep = $this->counterSale([['product_id' => $this->cap->id, 'qty' => 1]]);
        $gone = $this->counterSale([['product_id' => $this->shoe->id, 'qty' => 1]]);
        $this->as()->delete("/app/sales/{$gone->id}", ['reason' => 'wrong entry'])->assertSessionHas('success');

        [$from, $to] = $this->range();
        $split = Reports::profitByChannel($from, $to);

        $this->assertEqualsWithDelta(100, $split['offline']['profit'], 0.01);
        $this->assertSame(1, $split['offline']['bills']);
        $this->assertEqualsWithDelta(Reports::rangeStats($from, $to)['grossProfit'], $split['total']['profit'], 0.01);
        $this->assertTrue($keep->exists);
    }

    public function test_product_rows_split_by_channel_and_reconcile_to_the_totals(): void
    {
        $this->counterSale([['product_id' => $this->shoe->id, 'qty' => 2, 'discount' => 100], ['product_id' => $this->cap->id, 'qty' => 1]], ['discount' => 50]);
        $this->onlineOrder([['product_id' => $this->shoe->id, 'qty' => 1, 'discount' => 80]], ['discount' => 20, 'cod_amount' => 900]);

        [$from, $to] = $this->range();
        $report = Reports::productProfitByChannel($from, $to);
        $rows = collect($report['rows'])->keyBy('product_name');

        $this->assertEqualsWithDelta(700, $rows['Shoe']['offline_profit'], 0.01);
        $this->assertEqualsWithDelta(2, $rows['Shoe']['offline_qty'], 0.01);
        $this->assertEqualsWithDelta(320, $rows['Shoe']['online_profit'], 0.01);
        $this->assertEqualsWithDelta(1, $rows['Shoe']['online_qty'], 0.01);
        $this->assertEqualsWithDelta(1020, $rows['Shoe']['total_profit'], 0.01);
        $this->assertEqualsWithDelta(100, $rows['Cap']['offline_profit'], 0.01);
        $this->assertEqualsWithDelta(0, $rows['Cap']['online_profit'], 0.01);

        // the bill-level discounts (50 at the counter, 20 online) are the bridge from "sum of products" to the totals
        $this->assertEqualsWithDelta(50, $report['unallocated_discount']['offline'], 0.01);
        $this->assertEqualsWithDelta(20, $report['unallocated_discount']['online'], 0.01);
        $split = Reports::profitByChannel($from, $to);
        $this->assertEqualsWithDelta(
            collect($report['rows'])->sum('total_profit') - $report['unallocated_discount']['total'],
            $split['total']['profit'],
            0.01,
            'sum of the products minus the whole-bill discounts is the total profit',
        );
    }

    public function test_a_returned_online_pc_comes_off_the_online_column_of_that_product(): void
    {
        $order = $this->onlineOrder([['product_id' => $this->cap->id, 'qty' => 3]], ['cod_amount' => 600]);
        $this->counterSale([['product_id' => $this->cap->id, 'qty' => 1]]);
        $sale = $order->sale()->withVoided()->first();
        $item = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->firstOrFail();
        $this->as()->postJson('/app/returns/process', ['sale_id' => $sale->id, 'action' => 'refund', 'reason' => 'x', 'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']]])->assertOk();

        [$from, $to] = $this->range();
        $cap = collect(Reports::productProfitByChannel($from, $to)['rows'])->firstWhere('product_name', 'Cap');

        $this->assertEqualsWithDelta(2, $cap['online_qty'], 0.01, '3 sold online, 1 handed back');
        $this->assertEqualsWithDelta(200, $cap['online_profit'], 0.01);
        $this->assertEqualsWithDelta(1, $cap['offline_qty'], 0.01, 'the counter column is untouched by an online return');
    }

    public function test_the_profit_page_and_the_accounts_summary_show_the_three_numbers(): void
    {
        $this->counterSale([['product_id' => $this->shoe->id, 'qty' => 1]]);
        $this->onlineOrder([['product_id' => $this->cap->id, 'qty' => 2]], ['cod_amount' => 400]);

        $version = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];

        $page = $this->as()->get('/app/accounts/profit?preset=today', $inertia)->assertOk();
        $this->assertSame('App/Accounts/ProfitSplit', $page->json('component'));
        $this->assertEqualsWithDelta(400, $page->json('props.split.offline.profit'), 0.01);
        $this->assertEqualsWithDelta(200, $page->json('props.split.online.profit'), 0.01);
        $this->assertEqualsWithDelta(600, $page->json('props.split.total.profit'), 0.01);
        $this->assertTrue($page->json('props.hasOnline'));
        $this->assertCount(2, $page->json('props.products'));

        $acc = $this->as()->get('/app/accounts', $inertia)->assertOk();
        $this->assertEqualsWithDelta(600, $acc->json('props.profitMonth.total.profit'), 0.01);
    }

    public function test_a_shop_without_online_business_still_gets_a_working_page(): void
    {
        [$plain, $owner] = $this->createShopWithOwner();
        $this->grantFeature($plain, 'accounts');
        $p = Product::create(['shop_id' => $plain->id, 'name' => 'Pen', 'cost' => 5, 'price' => 10, 'stock' => 50]);
        Tenancy::clear();
        Tenancy::forgetShopCache();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', ['items' => [['product_id' => $p->id, 'qty' => 4]], 'payments' => [['method' => 'cash', 'amount' => 40]]])->assertOk();

        $version = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $page = $this->actingAs($owner, 'web')->get('/app/accounts/profit?preset=today', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk();

        $this->assertFalse($page->json('props.hasOnline'));
        $this->assertEqualsWithDelta(20, $page->json('props.split.total.profit'), 0.01);
        $this->assertEqualsWithDelta(0, $page->json('props.split.online.profit'), 0.01);
    }

    /**
     * Baby Park live (2026-11-27): a pair sold at the sibling branch and taken back at THIS counter. The profit total
     * subtracted the refund, but the product table could not see the sibling's sale line (tenant scope), so the table
     * stayed ৳350 high. Both must agree, and the return must be filed under the channel of its original sale.
     */
    public function test_a_return_taken_here_for_a_sale_made_at_the_sibling_branch_is_counted_everywhere(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        $this->grantFeature($main, 'accounts');
        $today = now()->toDateString();

        $sale = Sale::withoutGlobalScopes()->create([
            'shop_id' => $branch->id, 'invoice_no' => 'INV-X1', 'date' => $today, 'time' => now()->toTimeString(),
            'subtotal' => 2000, 'total' => 2000, 'profit' => 800, 'payment_mode' => 'cash',
        ]);
        $item = SaleItem::withoutGlobalScopes()->create([
            'shop_id' => $branch->id, 'sale_id' => $sale->id, 'product_name' => 'Pair', 'qty' => 2, 'price' => 1000, 'discount' => 0, 'cost' => 600,
        ]);
        // the customer brings ONE pair back to the MAIN counter — the return row belongs to the main shop
        \App\Models\SalesReturn::withoutGlobalScopes()->create([
            'shop_id' => $main->id, 'product_id' => $this->shoe->id, 'sale_id' => $sale->id, 'sale_item_id' => $item->id, 'qty' => 1, 'refund' => 1000, 'cost' => 600,
            'date' => $today, 'type' => 'refund', 'condition' => 'resalable',
        ]);
        // and the main shop sold one pair of its own
        $own = Sale::withoutGlobalScopes()->create([
            'shop_id' => $main->id, 'invoice_no' => 'INV-X2', 'date' => $today, 'time' => now()->toTimeString(),
            'subtotal' => 1000, 'total' => 1000, 'profit' => 400, 'payment_mode' => 'cash',
        ]);
        SaleItem::withoutGlobalScopes()->create([
            'shop_id' => $main->id, 'sale_id' => $own->id, 'product_name' => 'Pair', 'qty' => 1, 'price' => 1000, 'discount' => 0, 'cost' => 600,
        ]);

        Tenancy::clear();
        Tenancy::forgetShopCache();
        $this->actingAs($mainOwner, 'web');

        $split = Reports::profitByChannel($today, $today);
        $products = Reports::productProfitByChannel($today, $today);
        $pl = Reports::rangeStats($today, $today);

        // main shop: sold 1 pair (profit 400), handed back a pair from the sibling's sale (refund 1000, cost 600 -> -400)
        $this->assertEqualsWithDelta(0, $split['total']['profit'], 0.01);
        $this->assertEqualsWithDelta($pl['grossProfit'], $split['total']['profit'], 0.01);
        $row = collect($products['rows'])->firstWhere('product_name', 'Pair');
        $this->assertEqualsWithDelta(0, $row['offline_qty'], 0.01, '1 sold here, 1 taken back here');
        $this->assertEqualsWithDelta(0, $row['total_profit'], 0.01, 'the sibling\'s line still comes off this shop\'s product row');
        $this->assertEqualsWithDelta(
            $split['total']['profit'],
            collect($products['rows'])->sum('total_profit') - $products['unallocated_discount']['total'],
            0.01,
            'sum of the products minus whole-bill discounts equals the total profit',
        );
    }

    public function test_another_shops_sales_never_leak_into_the_numbers(): void
    {
        [$other, $otherOwner] = $this->createShopWithOwner();
        $theirs = Product::create(['shop_id' => $other->id, 'name' => 'Theirs', 'cost' => 1, 'price' => 99999, 'stock' => 5]);
        Sale::withoutGlobalScopes()->create([
            'shop_id' => $other->id, 'invoice_no' => 'X-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 99999, 'total' => 99999, 'profit' => 99998, 'payment_mode' => 'cash',
        ]);
        $this->counterSale([['product_id' => $this->cap->id, 'qty' => 1]]);

        [$from, $to] = $this->range();
        $this->as(); // the owner of THIS shop is the tenant
        $this->assertEqualsWithDelta(100, Reports::profitByChannel($from, $to)['total']['profit'], 0.01);
        $this->assertSame(['Cap'], collect(Reports::productProfitByChannel($from, $to)['rows'])->pluck('product_name')->all());
        $this->assertNotNull($theirs);
    }
}
