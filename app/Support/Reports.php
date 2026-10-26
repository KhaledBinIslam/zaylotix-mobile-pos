<?php

namespace App\Support;

use App\Models\Damage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PreparationItem;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
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

        // SQL-aggregated (see docs/SCALING_GUIDE.md item #6) — every figure
        // below was already a plain SUM()/COUNT() over the range, just
        // computed in PHP after fetching every row; MySQL's SUM() already
        // skips NULL (e.g. an old return row with no `cost` recorded) the
        // exact same way the old `$r->cost === null ? 0.0 : ...` guard did,
        // and `sale_type` is a NOT NULL column with a DB default, so there
        // is no row this could ever treat differently than before. Nothing
        // here reads an actual Sale/SalesReturn row anymore, only sums.
        $saleQuery = $inRange(Sale::query());

        // Gross sales includes an exchange's own replacement-item Sale
        // (sale_type='exchange') — it's a real sale of real goods, it's only
        // excluded from the *bill count*/visits/loyalty below ($countedSales),
        // never from revenue or profit.
        $grossSales = (float) (clone $saleQuery)->sum('total');
        $totalCostIssued = $grossSales - (float) (clone $saleQuery)->sum('profit');

        $countedSalesQuery = (clone $saleQuery)->where('sale_type', '!=', 'exchange');

        [$returnsTotal, $costRecovered] = self::returnTotals($inRange(SalesReturn::query()));

        // Net sales = gross sales minus whatever was actually handed back
        // (a plain refund or the "give back" half of an exchange) — a
        // returned sale's original revenue was never really kept, so this
        // is shown net rather than leaving the headline figure inflated by
        // money that went back out.
        $salesAmt = $grossSales - $returnsTotal;
        // Cost of a returned item comes back out of COGS the moment it's
        // credited back, whether it was resalable (back on the shelf) or
        // damaged (written off) — either way that cost was already expensed
        // once as part of the original sale. A damaged return's actual
        // loss still shows up correctly below via its own Damage entry (see
        // ReturnController::process) hitting $dmg; recovering its cost here
        // too is what keeps that from being a double subtraction rather than
        // creating one — the same cost figure feeds both places by
        // construction. Old return rows (saved before the `cost` column
        // existed) contribute 0 here, so their full refund stays subtracted
        // from net sales with nothing recovered — same as this report has
        // always treated them.
        $cogs = $totalCostIssued - $costRecovered;
        $grossProfit = $salesAmt - $cogs;

        $exp = (float) $inRange(Expense::query())->sum('amount');
        $dmg = (float) $inRange(Damage::query())->sum('loss');

        // Sum each sale's own stored `vat` (set at checkout time from the
        // shop's VAT mode/rate *then*), never re-derive it from the range
        // total using the shop's *current* settings — a shop that later
        // switches vat_mode or turnover_rate would otherwise see every past
        // report/export silently recalculate under the new rule and stop
        // matching what was actually charged on those invoices.
        $vat = (float) (clone $countedSalesQuery)->sum('vat');

        $net = $grossProfit - $exp - $dmg;

        return compact('grossSales', 'returnsTotal', 'salesAmt', 'cogs', 'grossProfit', 'exp', 'dmg', 'vat', 'net') + [
            'count' => (clone $countedSalesQuery)->count(),
        ];
    }

    /**
     * [total refund given, total cost recovered (known-cost rows only)] for
     * a SalesReturn query in range — the two building blocks rangeStats()/
     * combinedRangeStats() need to show returns as their own line without
     * double-counting a damaged return's cost against its own Damage entry.
     * Takes the query itself (not `->get()`'d rows) so both sums run as
     * plain SQL SUM() — MySQL already skips a NULL `cost` row exactly like
     * the old `$r->cost === null ? 0.0 : $r->cost` guard did by hand.
     */
    private static function returnTotals($returnsQuery): array
    {
        $returnsTotal = (float) (clone $returnsQuery)->sum('refund');
        $costRecovered = (float) (clone $returnsQuery)->sum('cost');

        return [$returnsTotal, $costRecovered];
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

        // SQL-aggregated — same reasoning as rangeStats() above, including
        // $byBranch below, which is now one GROUP BY shop_id query instead
        // of fetching every sale row just to re-group it in PHP.
        $saleQuery = $inRange(Sale::query());

        $grossSales = (float) (clone $saleQuery)->sum('total');
        $totalCostIssued = $grossSales - (float) (clone $saleQuery)->sum('profit');

        $countedSalesQuery = (clone $saleQuery)->where('sale_type', '!=', 'exchange');

        [$returnsTotal, $costRecovered] = self::returnTotals($inRange(SalesReturn::query()));

        $salesAmt = $grossSales - $returnsTotal;
        $cogs = $totalCostIssued - $costRecovered;
        $grossProfit = $salesAmt - $cogs;

        $exp = (float) $inRange(Expense::query())->sum('amount');
        $dmg = (float) $inRange(Damage::query())->sum('loss');
        $vat = (float) (clone $countedSalesQuery)->sum('vat');
        $net = $grossProfit - $exp - $dmg;

        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $shopIds)->pluck('name', 'id');
        $byBranchRows = (clone $saleQuery)
            ->selectRaw('shop_id, COUNT(*) as cnt, SUM(total) as total_sum, SUM(profit) as profit_sum')
            ->groupBy('shop_id')
            ->get();
        $byBranch = $byBranchRows->map(fn ($r) => [
            'shop_name' => $shopNames->get($r->shop_id, '—'),
            'count' => (int) $r->cnt,
            'total' => round((float) $r->total_sum, 2),
            'profit' => round((float) $r->profit_sum, 2),
        ])->sortByDesc('total')->values()->all();

        return compact('grossSales', 'returnsTotal', 'salesAmt', 'cogs', 'grossProfit', 'exp', 'dmg', 'vat', 'net', 'byBranch') + [
            'count' => (clone $countedSalesQuery)->count(),
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
        return self::productStats($from, $to)->sortByDesc('qty_sold')->take($limit)->values();
    }

    /** The mirror of topProducts — slowest-moving items in the range, so an owner can spot dead stock instead of only ever seeing what's selling well. Only ever includes a product that sold at least once in the range; a product with zero sales isn't "slow", it's simply unsold, and belongs on the stock/low-stock views instead. */
    public static function bottomProducts(string $from, string $to, int $limit = 20)
    {
        return self::productStats($from, $to)->sortBy('qty_sold')->take($limit)->values();
    }

    /** Per-product profit/margin — Khaled's explicit request: topProducts()/
     *  bottomProducts() already compute revenue and profit per product, but
     *  only to rank by quantity sold (for "what's moving" / "what's dead
     *  stock"), capped at 20 either way. This is the same underlying
     *  numbers, ranked by profit instead, uncapped up to a generous 200 (a
     *  shop with more distinct products sold in one range than that is
     *  itself a rare edge case, not worth an extra paginated screen for),
     *  so an owner can see which products are actually worth stocking. */
    public static function productProfitReport(string $from, string $to)
    {
        return self::productStats($from, $to)->sortByDesc('profit')->take(200)->values()->map(function ($row) {
            $revenue = (float) $row->revenue;
            $row->margin_pct = $revenue > 0 ? round(((float) $row->profit / $revenue) * 100, 1) : 0;

            return $row;
        });
    }

    /**
     * The shared base for topProducts/bottomProducts/productProfitReport —
     * per-product qty/revenue/profit from sale_items, with every
     * invoice-linked return (sale_item_id set — the old no-receipt flow has
     * no sale_item to attribute a name from) in the same range netted out.
     * Without this, a fully-refunded sale still showed as "sold" here even
     * though Reports::rangeStats()'s own P&L had already zeroed it out —
     * this keeps sum(profit) across every row here identical to
     * rangeStats()'s $grossProfit for the same range (same identity:
     * grossProfit = sum(sale profit) − returnImpact(all returns), just
     * distributed per product instead of summed once).
     */
    private static function productStats(string $from, string $to): \Illuminate\Support\Collection
    {
        $rows = SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('product_name, SUM(qty) as qty_sold, SUM(qty * price - discount) as revenue, SUM((price - cost) * qty - discount) as profit')
            ->groupBy('product_name')
            ->get()
            ->keyBy('product_name');

        $returns = SalesReturn::whereNotNull('sale_item_id')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->with('saleItem:id,product_name')
            ->get()
            ->filter(fn (SalesReturn $r) => $r->saleItem !== null)
            ->groupBy(fn (SalesReturn $r) => $r->saleItem->product_name);

        foreach ($returns as $productName => $group) {
            $qtyImpact = (float) $group->sum('qty');
            $revenueImpact = (float) $group->sum('refund');
            $profitImpact = (float) $group->sum(fn (SalesReturn $r) => $r->cost === null ? (float) $r->refund : (float) $r->refund - (float) $r->cost);

            if (! $rows->has($productName)) {
                // the original sale fell outside this range (e.g. returned
                // today against last week's sale) — still has to show up,
                // negative, or the product-sum-vs-P&L identity above breaks
                $rows->put($productName, (object) ['product_name' => $productName, 'qty_sold' => 0, 'revenue' => 0, 'profit' => 0]);
            }

            $row = $rows->get($productName);
            $row->qty_sold = (float) $row->qty_sold - $qtyImpact;
            $row->revenue = round((float) $row->revenue - $revenueImpact, 2);
            $row->profit = round((float) $row->profit - $profitImpact, 2);
        }

        return $rows->values();
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
     * Per-batch picture: what's left, what sold in this range, and which
     * supplier it came from — the batch-tracking counterpart to
     * itemWisePurchases(), read straight off the allocation records
     * BatchStock::deduct() already stamps onto each sale_item rather than
     * a second sold-qty ledger that could drift out of sync. Lists every
     * batch that's either still on hand or moved something in this range —
     * a long-empty batch from months ago doesn't clutter the list forever.
     */
    public static function batchWiseReport(string $from, string $to, int $limit = 100)
    {
        $soldByBatch = SaleItem::whereNotNull('batch_allocations')
            ->whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->get(['batch_allocations'])
            ->flatMap(fn (SaleItem $i) => $i->batch_allocations ?? [])
            ->groupBy('batch_id')
            ->map(fn ($group) => (float) $group->sum('qty'));

        return ProductBatch::with(['product:id,name,emoji', 'supplier:id,name'])
            ->where(fn ($q) => $q->where('qty', '>', 0)->orWhereIn('id', $soldByBatch->keys()))
            ->orderBy('expiry_date')
            ->limit($limit)
            ->get()
            ->map(fn (ProductBatch $b) => [
                'id' => $b->id,
                'product_name' => $b->product?->name ?? 'অজানা',
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'supplier_name' => $b->supplier?->name,
                'qty_remaining' => (float) $b->qty,
                'qty_sold' => (float) ($soldByBatch[$b->id] ?? 0),
                'cost' => round((float) $b->cost, 2),
            ])
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

    /**
     * Revenue/cost/profit/qty per product category over the range — a line
     * item with no category (or a since-deleted product) falls into a
     * single "uncategorized" bucket rather than being silently dropped.
     * Cost/profit added on top of the original qty/revenue-only version —
     * same per-line profit formula productStats() uses ((price-cost)*qty -
     * discount), so sum(profit) here still reconciles to rangeStats().
     */
    public static function categoryReport(string $from, string $to): array
    {
        $byCategory = SaleItem::whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->with('product.category')
            ->get()
            ->groupBy(fn (SaleItem $i) => $i->product?->category?->name ?: 'অশ্রেণীবদ্ধ')
            ->map(fn ($group) => [
                'qty' => (float) $group->sum('qty'),
                'revenue' => (float) $group->sum(fn (SaleItem $i) => $i->qty * $i->price - $i->discount),
                'profit' => (float) $group->sum(fn (SaleItem $i) => ($i->price - $i->cost) * $i->qty - $i->discount),
            ]);

        // same reasoning as productStats() — a return's refund/qty/cost must
        // come back out of its category's total too, or a fully-refunded
        // sale keeps inflating that category after the P&L already zeroed
        // it out.
        $returnsByCategory = SalesReturn::whereNotNull('sale_item_id')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->with('saleItem.product.category')
            ->get()
            ->filter(fn (SalesReturn $r) => $r->saleItem !== null)
            ->groupBy(fn (SalesReturn $r) => $r->saleItem->product?->category?->name ?: 'অশ্রেণীবদ্ধ');

        foreach ($returnsByCategory as $category => $group) {
            $existing = $byCategory->get($category, ['qty' => 0, 'revenue' => 0, 'profit' => 0]);
            $profitImpact = (float) $group->sum(fn (SalesReturn $r) => $r->cost === null ? (float) $r->refund : (float) $r->refund - (float) $r->cost);
            $byCategory->put($category, [
                'qty' => $existing['qty'] - (float) $group->sum('qty'),
                'revenue' => round($existing['revenue'] - (float) $group->sum('refund'), 2),
                'profit' => round($existing['profit'] - $profitImpact, 2),
            ]);
        }

        return $byCategory->map(function ($row) {
            $row['margin_pct'] = $row['revenue'] > 0 ? round(($row['profit'] / $row['revenue']) * 100, 1) : 0;

            return $row;
        })->sortByDesc('revenue')->all();
    }

    /**
     * Profit attributed to whoever rang up the sale (Sale.user_id) — the
     * profit counterpart to cashierCashBreakdown()'s cash-drawer view.
     * Returns reduce the selling user's own profit (SalesReturn.user_id is
     * whoever processed the return, normally the same cashier handling the
     * counter that shift, same attribution cashierCashBreakdown() already
     * uses for cash returns).
     */
    public static function salespersonProfitReport(string $from, string $to): array
    {
        $inRange = fn ($query) => $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        $byUser = $inRange(Sale::query())->where('sale_type', '!=', 'exchange')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'revenue' => (float) $group->sum('total'),
                'profit' => (float) $group->sum('profit'),
            ]);

        $returnsByUser = $inRange(SalesReturn::query())->get()->groupBy('user_id');
        foreach ($returnsByUser as $userId => $group) {
            if (! $userId) {
                continue;
            }
            $existing = $byUser->get($userId, ['count' => 0, 'revenue' => 0, 'profit' => 0]);
            $profitImpact = (float) $group->sum(fn (SalesReturn $r) => $r->cost === null ? (float) $r->refund : (float) $r->refund - (float) $r->cost);
            $byUser->put($userId, [
                'count' => $existing['count'],
                'revenue' => round($existing['revenue'] - (float) $group->sum('refund'), 2),
                'profit' => round($existing['profit'] - $profitImpact, 2),
            ]);
        }

        $users = User::whereIn('id', $byUser->keys()->filter())->get(['id', 'name', 'role'])->keyBy('id');

        return $byUser->map(function ($row, $userId) use ($users) {
            $row['user_id'] = $userId;
            $row['name'] = $users[$userId]->name ?? 'অজানা';
            $row['margin_pct'] = $row['revenue'] > 0 ? round(($row['profit'] / $row['revenue']) * 100, 1) : 0;

            return $row;
        })->sortByDesc('profit')->values()->all();
    }

    /**
     * Per-product profit broken down by branch — combinedRangeStats()'s
     * byBranch is already per-branch, but only as one aggregate total per
     * branch; this is the item-level cut across the same set of shops, for
     * a main shop comparing e.g. "which branch sells product X best".
     * Deliberately a separate method rather than folding into
     * combinedRangeStats() — that one feeds the always-shown summary card,
     * this one is a bigger, opt-in table.
     */
    public static function branchProductProfitReport(array $shopIds, string $from, string $to): array
    {
        $rows = SaleItem::withoutGlobalScopes()
            ->whereIn('shop_id', $shopIds)
            ->whereHas('sale', fn ($q) => $q->withoutGlobalScopes()->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('shop_id, product_name, SUM(qty) as qty_sold, SUM(qty * price - discount) as revenue, SUM((price - cost) * qty - discount) as profit')
            ->groupBy('shop_id', 'product_name')
            ->get();

        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $shopIds)->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'shop_id' => $r->shop_id,
            'shop_name' => $shopNames->get($r->shop_id, '—'),
            'product_name' => $r->product_name,
            'qty_sold' => (float) $r->qty_sold,
            'revenue' => round((float) $r->revenue, 2),
            'profit' => round((float) $r->profit, 2),
        ])->sortByDesc('profit')->values()->all();
    }

    /**
     * Per-IMEI/serial profit — a serial-tracked device is always qty=1 per
     * sale line, so this is just that line's own price/cost rather than an
     * aggregate. Excludes a line whose device was later returned (the
     * SalesReturn row already reverses that unit's sale via SaleReversal;
     * showing it here too would double it against rangeStats()).
     */
    public static function serialProfitReport(string $from, string $to, int $limit = 200)
    {
        $returnedItemIds = SalesReturn::whereNotNull('sale_item_id')
            ->whereHas('saleItem', fn ($q) => $q->whereNotNull('product_serial_id'))
            ->pluck('sale_item_id');

        return SaleItem::whereNotNull('product_serial_id')
            ->whereNotIn('id', $returnedItemIds)
            ->whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->with(['sale:id,invoice_no,date', 'serial:id,imei'])
            ->get()
            ->map(fn (SaleItem $i) => [
                'imei' => $i->serial?->imei ?? '—',
                'product_name' => $i->product_name,
                'invoice_no' => $i->sale?->invoice_no,
                'date' => $i->sale?->date?->toDateString(),
                'revenue' => round((float) $i->price - (float) $i->discount, 2),
                'cost' => round((float) $i->cost, 2),
                'profit' => round(((float) $i->price - (float) $i->cost) - (float) $i->discount, 2),
            ])
            ->sortByDesc('profit')
            ->take($limit)
            ->values();
    }

    /**
     * Per-variant profit (size/color), the profit counterpart to
     * variantInventoryBreakdown() — grouped by product_variant_id, not just
     * variant_label text, so two different products that happen to share a
     * label (e.g. both have a "42, কালো") never get merged into one row.
     */
    public static function variantProfitReport(string $from, string $to)
    {
        $rows = SaleItem::whereNotNull('product_variant_id')
            ->whereHas('sale', fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('product_variant_id, product_name, variant_label, SUM(qty) as qty_sold, SUM(qty * price - discount) as revenue, SUM((price - cost) * qty - discount) as profit')
            ->groupBy('product_variant_id', 'product_name', 'variant_label')
            ->get()
            ->keyBy('product_variant_id');

        $returns = SalesReturn::whereNotNull('product_variant_id')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()
            ->groupBy('product_variant_id');

        foreach ($returns as $variantId => $group) {
            if (! $rows->has($variantId)) {
                continue; // original sale fell outside this range — same as productStats(), rare enough to skip rather than reconstruct the row
            }
            $row = $rows->get($variantId);
            $profitImpact = (float) $group->sum(fn (SalesReturn $r) => $r->cost === null ? (float) $r->refund : (float) $r->refund - (float) $r->cost);
            $row->qty_sold = (float) $row->qty_sold - (float) $group->sum('qty');
            $row->revenue = round((float) $row->revenue - (float) $group->sum('refund'), 2);
            $row->profit = round((float) $row->profit - $profitImpact, 2);
        }

        return $rows->values()->sortByDesc('profit')->map(function ($row) {
            $revenue = (float) $row->revenue;
            $row->margin_pct = $revenue > 0 ? round(((float) $row->profit / $revenue) * 100, 1) : 0;

            return $row;
        })->values();
    }

    /**
     * A current stock snapshot (not date-ranged, unlike every report above —
     * "how much do I have right now", not "how much moved this week"),
     * broken down by category and by each color/size combination. Lets a
     * clothing shop answer "কোন category, color, size এ কতগুলো আছে" without
     * opening every product one at a time.
     */
    public static function variantInventoryBreakdown(): array
    {
        $rows = ProductVariant::with('product.category')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'product_id' => $v->product_id,
                'product_name' => $v->product->name ?? '',
                'category' => $v->product?->category?->name ?: 'অশ্রেণীবদ্ধ',
                'color' => $v->color,
                'size' => $v->size,
                'stock' => $v->stock,
            ]);

        $byCategory = $rows->groupBy('category')
            ->map(fn ($group, $category) => [
                'category' => $category,
                'total_stock' => (int) $group->sum('stock'),
                'variant_count' => $group->count(),
            ])
            ->sortByDesc('total_stock')
            ->values()
            ->all();

        return [
            'rows' => $rows->sortBy([['category', 'asc'], ['product_name', 'asc']])->values()->all(),
            'byCategory' => $byCategory,
        ];
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
                    'date' => $r->sale?->date?->toDateString(),
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
