<?php

namespace App\Support;

use App\Models\Damage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PreparationItem;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRating;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\User;

class Reports
{
    public static function rangeStats(string $from, string $to): array
    {
        $inRange = fn ($query) => $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        $sales = $inRange(Sale::query())->get();
        $salesAmt = (float) $sales->sum('total');
        $grossProfit = (float) $sales->sum('profit');
        $cogs = $salesAmt - $grossProfit;

        $exp = (float) $inRange(Expense::query())->sum('amount');
        $dmg = (float) $inRange(Damage::query())->sum('loss');
        $ret = (float) $inRange(SalesReturn::query())->sum('refund');

        // Sum each sale's own stored `vat` (set at checkout time from the
        // shop's VAT mode/rate *then*), never re-derive it from the range
        // total using the shop's *current* settings — a shop that later
        // switches vat_mode or turnover_rate would otherwise see every past
        // report/export silently recalculate under the new rule and stop
        // matching what was actually charged on those invoices.
        $vat = (float) $sales->sum('vat');

        $net = $grossProfit - $exp - $dmg - $ret;

        return compact('salesAmt', 'cogs', 'grossProfit', 'exp', 'dmg', 'ret', 'vat', 'net') + [
            'count' => $sales->count(),
        ];
    }

    /**
     * Same shape as rangeStats(), but across every shop_id in a multi-branch
     * business at once -- explicitly bypasses the tenant scope (Sale/Expense/
     * Damage/SalesReturn are all normally scoped to just Tenancy::id(), one
     * shop) since this is the one place summing across shops is intentional.
     * Also breaks the total down per branch so an owner can compare them.
     */
    public static function combinedRangeStats(array $shopIds, string $from, string $to): array
    {
        $inRange = fn ($query) => $query->withoutGlobalScopes()->whereIn('shop_id', $shopIds)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        $sales = $inRange(Sale::query())->get();
        $salesAmt = (float) $sales->sum('total');
        $grossProfit = (float) $sales->sum('profit');
        $cogs = $salesAmt - $grossProfit;

        $exp = (float) $inRange(Expense::query())->sum('amount');
        $dmg = (float) $inRange(Damage::query())->sum('loss');
        $ret = (float) $inRange(SalesReturn::query())->sum('refund');
        $vat = (float) $sales->sum('vat');
        $net = $grossProfit - $exp - $dmg - $ret;

        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $shopIds)->pluck('name', 'id');
        $byBranch = $sales->groupBy('shop_id')->map(fn ($group, $shopId) => [
            'shop_name' => $shopNames->get($shopId, '—'),
            'count' => $group->count(),
            'total' => round((float) $group->sum('total'), 2),
            'profit' => round((float) $group->sum('profit'), 2),
        ])->sortByDesc('total')->values()->all();

        return compact('salesAmt', 'cogs', 'grossProfit', 'exp', 'dmg', 'ret', 'vat', 'net', 'byBranch') + [
            'count' => $sales->count(),
        ];
    }

    /**
     * Per-product performance for the range — qty sold, revenue, and profit
     * — grouped by the sale_items.product_name *snapshot*, not the live
     * product_id, so a since-renamed or since-deleted product still shows
     * up correctly under the name it was actually sold under. Ordered by
     * qty sold, so this doubles as both the "top-selling items" and
     * "item-wise profit margin" report Khaled asked for — the same table
     * answers both.
     */
    /**
     * Per-cashier cash-drawer reconciliation: how much cash each staff
     * member (or the owner, if they also work the counter) personally rang
     * up/collected/refunded during the range — so it can be checked against
     * what's actually handed over at shift-end. Deliberately limited to
     * sales, due-collections, and refunds — the everyday counter actions —
     * not purchases/expenses, which are normally an owner/back-office
     * decision rather than something attributable to a specific cashier's
     * drawer.
     */
    public static function cashierCashBreakdown(string $from, string $to): array
    {
        $inRange = fn ($query) => $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        $cashSalesByUser = $inRange(Sale::query())
            ->with('payments')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($sales) => (float) $sales->sum(fn ($s) => $s->payments->where('method', 'cash')->sum('amount')));

        $cashDueByUser = $inRange(Payment::where('method', 'cash'))
            ->get()
            ->groupBy('user_id')
            ->map(fn ($payments) => (float) $payments->sum('amount'));

        $cashReturnsByUser = $inRange(SalesReturn::query())
            ->get()
            ->groupBy('user_id')
            ->map(fn ($returns) => (float) $returns->sum('refund'));

        $userIds = $cashSalesByUser->keys()
            ->merge($cashDueByUser->keys())
            ->merge($cashReturnsByUser->keys())
            ->filter() // drop the null bucket — no attributable user
            ->unique();

        $users = User::whereIn('id', $userIds)->get(['id', 'name', 'role'])->keyBy('id');

        return $userIds->map(function ($uid) use ($cashSalesByUser, $cashDueByUser, $cashReturnsByUser, $users) {
            $sales = $cashSalesByUser[$uid] ?? 0.0;
            $due = $cashDueByUser[$uid] ?? 0.0;
            $returns = $cashReturnsByUser[$uid] ?? 0.0;

            return [
                'user_id' => $uid,
                'name' => $users[$uid]->name ?? '—',
                'role' => $users[$uid]->role ?? null,
                'cash_sales' => round($sales, 2),
                'cash_due_collected' => round($due, 2),
                'cash_returns' => round($returns, 2),
                'expected_cash' => round($sales + $due - $returns, 2),
            ];
        })->sortByDesc('expected_cash')->values()->all();
    }

    public static function topProducts(string $from, string $to, int $limit = 20)
    {
        return SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('product_name, SUM(qty) as qty_sold, SUM(qty * price - discount) as revenue, SUM((price - cost) * qty - discount) as profit')
            ->groupBy('product_name')
            ->orderByDesc('qty_sold')
            ->limit($limit)
            ->get();
    }

    /** The mirror of topProducts — slowest-moving items in the range, so an owner can spot dead stock instead of only ever seeing what's selling well. Only ever includes a product that sold at least once in the range; a product with zero sales isn't "slow", it's simply unsold, and belongs on the stock/low-stock views instead. */
    public static function bottomProducts(string $from, string $to, int $limit = 20)
    {
        return SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('product_name, SUM(qty) as qty_sold, SUM(qty * price - discount) as revenue, SUM((price - cost) * qty - discount) as profit')
            ->groupBy('product_name')
            ->orderBy('qty_sold')
            ->limit($limit)
            ->get();
    }

    /** Retail vs wholesale split — how much of the range's revenue/profit came from each sale_type (see the wholesale_pricing migration). */
    public static function salesByType(string $from, string $to): array
    {
        $sales = Sale::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();

        return $sales->groupBy(fn (Sale $s) => $s->sale_type ?? 'retail')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('total'), 2),
                'profit' => round((float) $group->sum('profit'), 2),
            ])->all();
    }

    /** Item-wise purchase totals — the purchase-side mirror of topProducts, since Khaled asked for "কী কিনলাম" to be as visible as "কী বিক্রি করলাম". Only purchases that actually named a product (a money-only purchase, e.g. paying a utility bill, has no product_id and is excluded). */
    public static function itemWisePurchases(string $from, string $to, int $limit = 50)
    {
        return Purchase::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereNotNull('product_id')
            ->with('product:id,name,emoji')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($group) => [
                'product_name' => $group->first()->product?->name ?? $group->first()->supplier ?? 'অজানা',
                'qty' => (float) $group->sum('qty'),
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->take($limit)
            ->values();
    }

    /**
     * Restaurant-only breakdown: how much came from dine-in vs takeaway vs
     * each named 3rd-party delivery platform, and (separately) how much
     * each named waiter personally rang up — both read off `table_order_id`
     * (every restaurant-origin sale carries one, split bill or not), never
     * duplicated onto the sale itself.
     */
    public static function restaurantBreakdown(string $from, string $to): array
    {
        $sales = Sale::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereNotNull('table_order_id')
            ->with('tableOrder')
            ->get();

        $bySource = $sales->groupBy(function (Sale $s) {
            $order = $s->tableOrder;
            if (! $order) {
                return 'অজানা';
            }

            return match ($order->order_source) {
                'delivery' => $order->delivery_platform ?: 'ডেলিভারি (নাম নেই)',
                'takeaway' => 'টেকঅ্যাওয়ে',
                default => 'ডাইন-ইন',
            };
        })->map(fn ($group) => ['count' => $group->count(), 'total' => round((float) $group->sum('total'), 2)])
            ->sortByDesc('total')
            ->all();

        $byWaiter = $sales->filter(fn (Sale $s) => filled($s->tableOrder?->waiter_name))
            ->groupBy(fn (Sale $s) => $s->tableOrder->waiter_name)
            ->map(fn ($group) => ['count' => $group->count(), 'total' => round((float) $group->sum('total'), 2)])
            ->sortByDesc('total')
            ->all();

        return ['by_source' => $bySource, 'by_waiter' => $byWaiter];
    }

    /** Revenue/qty per product category over the range — a line item with no category (or a since-deleted product) falls into a single "uncategorized" bucket rather than being silently dropped. */
    public static function categoryReport(string $from, string $to): array
    {
        return SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->with('product.category')
            ->get()
            ->groupBy(fn (SaleItem $i) => $i->product?->category?->name ?: 'অশ্রেণীবদ্ধ')
            ->map(fn ($group) => [
                'qty' => (float) $group->sum('qty'),
                'revenue' => round((float) $group->sum(fn (SaleItem $i) => $i->qty * $i->price - $i->discount), 2),
            ])
            ->sortByDesc('revenue')
            ->all();
    }

    /**
     * Total discount given away over the range, split into the overall
     * per-sale discount (Sale.discount) and the sum of every line's own
     * discount (SaleItem.discount) — the two are never double-counted
     * elsewhere in the app (an item discount reduces what a sale's own
     * `discount` field would otherwise need to cover), so they're reported
     * as two distinct figures rather than added together.
     */
    public static function discountReport(string $from, string $to): array
    {
        $inRange = fn ($query) => $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        // a complimentary sale's discount = its full subtotal (see
        // PosController::performCheckout) — that's a gift, not merchandising
        // discount, and belongs in complimentaryReport() instead, not here
        $sales = $inRange(Sale::query())->where('is_complimentary', false)->get();
        $overallDiscount = (float) $sales->sum('discount');
        $itemDiscount = (float) SaleItem::whereIn('sale_id', $sales->pluck('id'))->sum('discount');

        return [
            'overall_discount' => round($overallDiscount, 2),
            'item_discount' => round($itemDiscount, 2),
            'total' => round($overallDiscount + $itemDiscount, 2),
            'sales_with_discount' => $sales->filter(fn (Sale $s) => (float) $s->discount > 0)->count(),
        ];
    }

    /** Free/staff-meal sales over the range — separate from discountReport() since these are gifts, not merchandising discounts. */
    public static function complimentaryReport(string $from, string $to): array
    {
        $sales = Sale::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->where('is_complimentary', true)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get();

        return [
            'count' => $sales->count(),
            'value_given_away' => round((float) $sales->sum('subtotal'), 2),
            'sales' => $sales->map(fn (Sale $s) => [
                'id' => $s->id, 'invoice_no' => $s->invoice_no, 'date' => $s->date->toDateString(),
                'subtotal' => (float) $s->subtotal, 'user_name' => $s->user?->name,
            ])->values(),
        ];
    }

    /** Wastage over the range, grouped by product and reason — a view over the existing Damage entity, not a new one. */
    public static function wastageReport(string $from, string $to, int $limit = 50)
    {
        return Damage::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->with('product:id,name,emoji')
            ->get()
            ->groupBy(fn (Damage $d) => $d->product_id.':'.$d->reason)
            ->map(fn ($group) => [
                'product_name' => $group->first()->product?->name ?? 'অজানা',
                'reason' => $group->first()->reason,
                'qty' => (float) $group->sum('qty'),
                'loss' => round((float) $group->sum('loss'), 2),
            ])
            ->sortByDesc('loss')
            ->take($limit)
            ->values();
    }

    /** Average customer rating + the lowest-rated feedback in the range, from the public /rate/{sale} page. */
    public static function ratingReport(string $from, string $to): array
    {
        $ratings = SaleRating::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->with('sale:id,invoice_no,date')
            ->get();

        return [
            'average' => $ratings->count() ? round($ratings->avg('stars'), 2) : null,
            'count' => $ratings->count(),
            'low' => $ratings->where('stars', '<=', 3)
                ->sortBy('stars')
                ->take(20)
                ->map(fn (SaleRating $r) => [
                    'invoice_no' => $r->sale?->invoice_no,
                    'stars' => $r->stars,
                    'comment' => $r->comment,
                    'date' => $r->sale?->date,
                ])->values(),
        ];
    }

    /** Ingredient usage over the range, from recorded Preparation batches -- grouped by ingredient since the same one likely feeds several dishes. */
    public static function consumptionReport(string $from, string $to): array
    {
        return PreparationItem::whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)
            ->get()
            ->groupBy('ingredient_name')
            ->map(fn ($group, $name) => ['name' => $name, 'qty' => round((float) $group->sum('qty_consumed'), 3)])
            ->sortByDesc('qty')
            ->values()
            ->all();
    }

    /**
     * Sales volume by hour-of-day (0-23) × day-of-week (0=Sunday..6=Saturday)
     * over the range — a plain grid of counts, not a chart, since no
     * charting library exists in this project yet and one row of numbers
     * per weekday is enough to spot a shop's busy hours.
     */
    public static function heatmap(string $from, string $to): array
    {
        $sales = Sale::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['date', 'time', 'total']);

        // grid[dayOfWeek][hour] = ['count' => n, 'total' => sum]
        $grid = [];
        foreach (range(0, 6) as $d) {
            foreach (range(0, 23) as $h) {
                $grid[$d][$h] = ['count' => 0, 'total' => 0.0];
            }
        }

        foreach ($sales as $sale) {
            $dow = (int) \Carbon\Carbon::parse($sale->date)->dayOfWeek;
            $hour = (int) substr((string) $sale->time, 0, 2);
            $grid[$dow][$hour]['count']++;
            $grid[$dow][$hour]['total'] += (float) $sale->total;
        }

        return $grid;
    }
}
