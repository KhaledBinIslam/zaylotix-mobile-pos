<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseChannelSplit;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\SalaryPayment;
use App\Models\SalesChannel;
use App\Models\SalesReturn;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * "Online Business" module, part 6 — see docs/FIX_PLAN.md's Phase 36
 * design note. View A (consolidated()) and View B (byChannel()) are
 * both built from the SAME per-channel rows (channelRows()), so A's
 * own "online" totals are never a second formula that could drift
 * from B's sum — they ARE the sum, by construction.
 *
 * Two deliberately separate cohorts per channel row: financial figures
 * (revenue/cogs/courier/ads/fees/damage/profit) use the delivered_at
 * range — same revenue-recognition rule as the rest of this module —
 * while operational figures (orders placed, status breakdown) use the
 * created_at range evaluated by current status. They are NOT meant to
 * arithmetically tie to each other; an order placed in one period can
 * resolve (deliver/return/cancel) in the next.
 */
class OnlineProfitReport
{
    public static function consolidated(string $from, string $to): array
    {
        $shop = Shop::findOrFail(Tenancy::id());
        $channels = SalesChannel::get(['id', 'name', 'platform_fee_percent', 'gateway_fee_percent', 'overhead_allocation_percent']);
        $rows = self::channelRows($channels, $from, $to, $shop);

        $totalOverhead = self::totalOverheadPool($from, $to);
        $allocatedOverhead = round((float) $rows->sum('overhead_share'), 2);

        $online = [
            'revenue' => round((float) $rows->sum('revenue'), 2),
            'cogs' => round((float) $rows->sum('cogs'), 2),
            'courier_cost' => round((float) $rows->sum('courier_cost'), 2),
            'ad_spend' => round((float) $rows->sum('ad_spend'), 2),
            'platform_gateway_fees' => round((float) $rows->sum('platform_gateway_fees'), 2),
            'damage_loss' => round((float) $rows->sum('damage_loss'), 2),
            'overhead' => $allocatedOverhead,
            'gross_profit' => round((float) $rows->sum('gross_profit'), 2),
            'net_profit' => round((float) $rows->sum('net_profit'), 2),
        ];

        $stockValue = (float) Product::selectRaw('COALESCE(SUM(cost * stock), 0) as v')->value('v');
        $codPending = (float) OnlineOrder::where('status', 'delivered')->whereNull('cod_settled_at')->sum('cod_amount');

        return [
            'from' => $from, 'to' => $to,
            'online' => $online,
            'total_overhead_pool' => round($totalOverhead, 2),
            'unallocated_overhead' => round(max(0, $totalOverhead - $allocatedOverhead), 2),
            'overhead_rule' => $shop->overhead_allocation_rule,
            'stock_value' => round($stockValue, 2),
            'cod_pending' => round($codPending, 2),
        ];
    }

    public static function byChannel(string $from, string $to): array
    {
        $shop = Shop::findOrFail(Tenancy::id());
        $channels = SalesChannel::orderBy('name')->get(['id', 'name', 'platform_type', 'platform_fee_percent', 'gateway_fee_percent', 'overhead_allocation_percent']);

        return self::channelRows($channels, $from, $to, $shop)->values()->all();
    }

    /** Shared overhead pool for the period — SalaryPayment (payroll) + every Expense WITHOUT a channel split (i.e. not ad spend), exactly Part 5's own definition, unchanged. */
    private static function totalOverheadPool(string $from, string $to): float
    {
        return (float) SalaryPayment::whereBetween('paid_date', [$from, $to])->sum('net_paid')
            + (float) Expense::doesntHave('channelSplits')->whereBetween('date', [$from, $to])->sum('amount');
    }

    private static function channelRows(Collection $channels, string $from, string $to, Shop $shop): Collection
    {
        $totalOverhead = self::totalOverheadPool($from, $to);

        $adSpendByChannel = ExpenseChannelSplit::whereHas('expense', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->selectRaw('channel_id, sum(amount) as total')->groupBy('channel_id')->pluck('total', 'channel_id');

        $rows = $channels->map(function (SalesChannel $channel) use ($from, $to, $adSpendByChannel) {
            // operational cohort — placed in this period, counted by current status
            $placed = OnlineOrder::where('channel_id', $channel->id)
                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->get(['id', 'status']);

            // financial cohort — delivered (ever) in this period, regardless of what happened after
            $delivered = OnlineOrder::where('channel_id', $channel->id)
                ->whereNotNull('delivered_at')
                ->whereBetween('delivered_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->with('sale.items')->get()->filter(fn (OnlineOrder $o) => $o->sale);

            $saleIds = $delivered->pluck('sale_id');
            $returns = $saleIds->isEmpty() ? collect() : SalesReturn::withoutGlobalScopes()->whereIn('sale_id', $saleIds)->get();

            $grossSales = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->sale->total);
            $grossMarginRaw = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->sale->profit);
            $returnRefund = (float) $returns->sum('refund');
            $returnCostRecovered = (float) $returns->whereNotNull('cost')->sum('cost');
            $damageLoss = (float) $returns->whereIn('condition', ['damaged', 'lost'])->sum('cost');

            $revenue = round($grossSales - $returnRefund, 2);
            // same residual identity Reports::rangeStats() already relies on: cogs is
            // backed out from gross sales and recorded profit, never independently re-derived
            $cogs = round(($grossSales - $grossMarginRaw) - $returnCostRecovered, 2);

            $deliveryChargeCollected = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->delivery_charge);
            $courierCost = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->courier_charge);
            $feeRate = ((float) ($channel->platform_fee_percent ?? 0) + (float) ($channel->gateway_fee_percent ?? 0)) / 100;
            $fees = round($revenue * $feeRate, 2);
            $adSpend = round((float) ($adSpendByChannel->get($channel->id) ?? 0), 2);

            $grossProfit = round(
                ($revenue - $cogs) + $deliveryChargeCollected - $courierCost - $fees - $damageLoss - $adSpend,
                2
            );

            $deliveredReturnedCount = $delivered->whereIn('status', ['returning', 'returned'])->count();

            return [
                'channel_id' => $channel->id,
                'channel_name' => $channel->name,
                'overhead_allocation_percent' => $channel->overhead_allocation_percent !== null ? (float) $channel->overhead_allocation_percent : null,
                // operational (created_at cohort, current status)
                'orders_placed' => $placed->count(),
                'status_breakdown' => $placed->countBy('status'),
                // financial (delivered_at cohort)
                'delivered_count' => $delivered->count(),
                'returned_count' => $deliveredReturnedCount,
                'units_sold' => (int) $delivered->sum(fn (OnlineOrder $o) => (float) $o->sale->items->sum('qty')),
                'units_returned' => (float) $returns->sum('qty'),
                'revenue' => $revenue,
                'cogs' => $cogs,
                'courier_cost' => round($courierCost, 2),
                'ad_spend' => $adSpend,
                'platform_gateway_fees' => $fees,
                'damage_loss' => round($damageLoss, 2),
                'gross_profit' => $grossProfit,
                'aov' => $delivered->count() > 0 ? round($revenue / $delivered->count(), 2) : null,
                'roas' => $adSpend > 0 ? round($revenue / $adSpend, 2) : null,
                'return_pct' => $delivered->count() > 0 ? round($deliveredReturnedCount / $delivered->count() * 100, 1) : null,
            ];
        });

        $totalRevenue = (float) $rows->sum('revenue');
        $channelCount = $rows->count();

        return $rows->map(function ($row) use ($shop, $totalOverhead, $totalRevenue, $channelCount) {
            $overheadShare = match ($shop->overhead_allocation_rule) {
                'equal' => $channelCount > 0 ? $totalOverhead / $channelCount : 0.0,
                'revenue_share' => $totalRevenue > 0 ? $totalOverhead * ($row['revenue'] / $totalRevenue) : ($channelCount > 0 ? $totalOverhead / $channelCount : 0.0),
                'manual' => $totalOverhead * (($row['overhead_allocation_percent'] ?? 0) / 100),
                default => 0.0,
            };

            $row['overhead_share'] = round($overheadShare, 2);
            $row['net_profit'] = round($row['gross_profit'] - $overheadShare, 2);

            return $row;
        });
    }
}
