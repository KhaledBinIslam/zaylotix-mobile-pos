<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseChannelSplit;
use App\Models\OnlineOrder;
use App\Models\SalaryPayment;
use App\Models\SalesChannel;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** "Online Business" module, part 5 — see docs/FIX_PLAN.md's Phase 35 design note. */
class AdSpendController extends Controller
{
    public function index(Request $request)
    {
        $shop = Shop::findOrFail(Tenancy::id());
        $preset = $request->get('preset', 'month');
        [$from, $to] = $this->resolveRange($preset, $request->get('from'), $request->get('to'));

        $channels = SalesChannel::orderBy('name')->get(['id', 'name', 'platform_type', 'overhead_allocation_percent']);

        $adExpenses = Expense::with('channelSplits.channel:id,name')
            ->has('channelSplits')
            ->whereBetween('date', [$from, $to])
            ->latest('id')
            ->get();

        return Inertia::render('App/OnlineBusiness/Ads/Index', [
            'from' => $from, 'to' => $to, 'preset' => $preset,
            'channels' => $channels,
            'adExpenses' => $adExpenses,
            'campaigns' => $this->campaignPerformance($from, $to),
            'channelEconomics' => $this->channelEconomics($channels, $from, $to, $shop),
            'overheadRule' => $shop->overhead_allocation_rule,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'campaign_tag' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'amount_usd' => ['nullable', 'numeric', 'min:0.01'],
            'exchange_rate' => ['required_with:amount_usd', 'nullable', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank'])],
            'date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'splits' => ['required', 'array', 'min:1'],
            'splits.*.channel_id' => ['required', Rule::exists('sales_channels', 'id')->where('shop_id', Tenancy::id())],
            'splits.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'splits.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (empty($data['amount']) && empty($data['amount_usd'])) {
            throw ValidationException::withMessages(['amount' => 'টাকার পরিমাণ (BDT বা USD) দিতে হবে।']);
        }

        $totalBdt = ! empty($data['amount_usd'])
            ? round((float) $data['amount_usd'] * (float) $data['exchange_rate'], 2)
            : round((float) $data['amount'], 2);

        // percent-mode: every row but the last is round(total * percent / 100);
        // the LAST row takes whatever remainder makes the split sum to the
        // total exactly, so rounding never leaves a stray paisa unaccounted
        // for — see docs/FIX_PLAN.md's Phase 35 design note.
        $splitMode = collect($data['splits'])->contains(fn ($s) => isset($s['percent'])) ? 'percent' : 'amount';
        $splitAmounts = [];
        $running = 0.0;
        $lastIndex = count($data['splits']) - 1;
        foreach (array_values($data['splits']) as $i => $split) {
            if ($splitMode === 'percent') {
                $amount = $i === $lastIndex ? round($totalBdt - $running, 2) : round($totalBdt * (float) ($split['percent'] ?? 0) / 100, 2);
            } else {
                $amount = round((float) ($split['amount'] ?? 0), 2);
            }
            $splitAmounts[] = ['channel_id' => $split['channel_id'], 'amount' => $amount];
            $running += $amount;
        }

        if ($splitMode === 'amount' && abs($running - $totalBdt) > 0.01) {
            throw ValidationException::withMessages([
                'splits' => 'চ্যানেলের ভাগের যোগফল (৳'.round($running, 2).") মোট টাকার (৳{$totalBdt}) সমান হচ্ছে না।",
            ]);
        }

        DB::transaction(function () use ($data, $totalBdt, $splitAmounts) {
            $category = ExpenseCategory::firstOrCreate(
                ['name' => 'বিজ্ঞাপন'],
                ['name_en' => 'Advertising', 'emoji' => '📢']
            );

            $expense = Expense::create([
                'expense_category_id' => $category->id,
                'memo' => $data['memo'] ?? null,
                'amount' => $totalBdt,
                'amount_usd' => $data['amount_usd'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'campaign_tag' => $data['campaign_tag'],
                'method' => $data['method'],
                'date' => $data['date'] ?? now()->toDateString(),
            ]);

            foreach ($splitAmounts as $split) {
                ExpenseChannelSplit::create([
                    'expense_id' => $expense->id,
                    'channel_id' => $split['channel_id'],
                    'amount' => $split['amount'],
                ]);
            }

            // same balance-decrement behavior as ExpenseController::store()
            // — ad spend is a real Expense, not a parallel money movement
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
            Shop::whereKey(Tenancy::id())->decrement($field, $totalBdt);
        });

        return back()->with('success', 'বিজ্ঞাপন খরচ যোগ করা হয়েছে।');
    }

    public function updateOverheadRule(Request $request)
    {
        $data = $request->validate([
            'rule' => ['required', Rule::in(['equal', 'revenue_share', 'manual'])],
            'percentages' => ['required_if:rule,manual', 'array'],
            'percentages.*.channel_id' => ['required_with:percentages', 'integer'],
            'percentages.*.percent' => ['required_with:percentages', 'numeric', 'min:0', 'max:100'],
        ]);

        DB::transaction(function () use ($data) {
            Shop::whereKey(Tenancy::id())->update(['overhead_allocation_rule' => $data['rule']]);

            if ($data['rule'] === 'manual') {
                foreach ($data['percentages'] as $row) {
                    SalesChannel::whereKey($row['channel_id'])->update(['overhead_allocation_percent' => $row['percent']]);
                }
            }
        });

        return back()->with('success', 'ওভারহেড বণ্টনের নিয়ম আপডেট হয়েছে।');
    }

    private function resolveRange(string $preset, ?string $from, ?string $to): array
    {
        return match ($preset) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
            'custom' => [$from ?: now()->startOfMonth()->toDateString(), $to ?: now()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString()], // month
        };
    }

    /**
     * Per-campaign ad ROI — ad spend tagged with a campaign vs. the online
     * orders carrying the same campaign_tag. Revenue only counts delivered
     * orders (via each order's own Sale, already hidden from every report
     * until delivered by Sale's own 'active' scope — see docs/FIX_PLAN.md's
     * Phase 32 note) — same revenue-recognition rule as everywhere else in
     * this module.
     */
    private function campaignPerformance(string $from, string $to): array
    {
        $spendByTag = Expense::has('channelSplits')
            ->whereNotNull('campaign_tag')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('campaign_tag, sum(amount) as spend')
            ->groupBy('campaign_tag')
            ->pluck('spend', 'campaign_tag');

        $ordersByTag = OnlineOrder::whereNotNull('campaign_tag')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->with('sale')
            ->get()
            ->groupBy('campaign_tag');

        $tags = $spendByTag->keys()->merge($ordersByTag->keys())->unique();

        return $tags->map(function ($tag) use ($spendByTag, $ordersByTag) {
            $tagOrders = $ordersByTag->get($tag, collect());
            $delivered = $tagOrders->filter(fn (OnlineOrder $o) => $o->status === 'delivered' && $o->sale);
            $revenue = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->sale->total);
            $spend = (float) ($spendByTag->get($tag) ?? 0);

            return [
                'campaign_tag' => $tag,
                'spend' => round($spend, 2),
                'orders' => $tagOrders->count(),
                'delivered' => $delivered->count(),
                'revenue' => round($revenue, 2),
                'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
                'cost_per_order' => $tagOrders->count() > 0 ? round($spend / $tagOrders->count(), 2) : null,
                'cost_per_delivered' => $delivered->count() > 0 ? round($spend / $delivered->count(), 2) : null,
            ];
        })->sortByDesc('spend')->values()->all();
    }

    /**
     * Channel-wise profit before/after shared overhead — a lightweight
     * preview of Part 6's full profit engine, scoped to what Part 5 adds
     * (ad spend + overhead). Gross profit reuses Sale.profit (already
     * revenue-minus-COGS at checkout) rather than re-deriving cost here;
     * Part 6 formalizes the complete, formally-tested figure.
     */
    private function channelEconomics(Collection $channels, string $from, string $to, Shop $shop): array
    {
        $totalOverhead = (float) SalaryPayment::whereBetween('paid_date', [$from, $to])->sum('net_paid')
            + (float) Expense::doesntHave('channelSplits')->whereBetween('date', [$from, $to])->sum('amount');

        $adSpendByChannel = ExpenseChannelSplit::whereHas('expense', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->selectRaw('channel_id, sum(amount) as total')
            ->groupBy('channel_id')
            ->pluck('total', 'channel_id');

        $rows = $channels->map(function (SalesChannel $channel) use ($from, $to, $adSpendByChannel) {
            $delivered = OnlineOrder::where('channel_id', $channel->id)->where('status', 'delivered')
                ->whereBetween('delivered_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->with('sale')->get()->filter(fn (OnlineOrder $o) => $o->sale);

            $revenue = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->sale->total);
            $grossProfit = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->sale->profit);
            $courierCharge = (float) $delivered->sum(fn (OnlineOrder $o) => (float) $o->courier_charge);
            $adSpend = (float) ($adSpendByChannel->get($channel->id) ?? 0);

            return [
                'channel_id' => $channel->id,
                'channel_name' => $channel->name,
                'overhead_allocation_percent' => $channel->overhead_allocation_percent !== null ? (float) $channel->overhead_allocation_percent : null,
                'revenue' => round($revenue, 2),
                'gross_profit' => round($grossProfit, 2),
                'courier_charge' => round($courierCharge, 2),
                'ad_spend' => round($adSpend, 2),
                'profit_before_overhead' => round($grossProfit - $courierCharge - $adSpend, 2),
            ];
        });

        $totalRevenue = (float) $rows->sum('revenue');
        $channelCount = $rows->count();

        $rows = $rows->map(function ($row) use ($shop, $totalOverhead, $totalRevenue, $channelCount) {
            $overheadShare = match ($shop->overhead_allocation_rule) {
                'equal' => $channelCount > 0 ? $totalOverhead / $channelCount : 0.0,
                'revenue_share' => $totalRevenue > 0 ? $totalOverhead * ($row['revenue'] / $totalRevenue) : ($channelCount > 0 ? $totalOverhead / $channelCount : 0.0),
                'manual' => $totalOverhead * (($row['overhead_allocation_percent'] ?? 0) / 100),
                default => 0.0,
            };

            $row['overhead_share'] = round($overheadShare, 2);
            $row['profit_after_overhead'] = round($row['profit_before_overhead'] - $overheadShare, 2);

            return $row;
        })->values();

        return [
            'rows' => $rows->all(),
            'total_overhead' => round($totalOverhead, 2),
            'total_revenue' => round($totalRevenue, 2),
        ];
    }
}
