<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Expense;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\Reports;
use App\Support\StockValuation;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AccountsController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();

        $stockValue = $this->stockValue();
        $receivable = (float) Customer::sum('due');
        // a delivered online order's COD is money the courier has already
        // collected from the customer but hasn't yet handed to the shop —
        // a real receivable until CourierController::settle() turns it
        // into actual cash_balance (see docs/FIX_PLAN.md's Phase 33 design
        // note). Pending/in-transit orders aren't counted — the courier
        // hasn't collected anything from the customer yet at that point.
        $codReceivable = (float) OnlineOrder::where('status', 'delivered')->whereNull('cod_settled_at')->sum('cod_amount');
        // Supplier::payable settles as SupplierPaymentController pays it
        // down; a credit purchase with no supplier chosen has no entity to
        // settle against, so (like before this feature existed) it stays a
        // permanent payable — summed separately since it's a different
        // running total (Purchase.amount, not Supplier.payable).
        $payable = (float) Supplier::sum('payable')
            + (float) Purchase::whereNull('supplier_id')->where('method', 'credit')->sum('amount');
        $assets = (float) $shop->cash_balance + (float) $shop->bank_balance + $stockValue + $receivable + $codReceivable;
        $liabilities = $payable;
        $netWorth = $assets - $liabilities;

        return Inertia::render('App/Accounts/Index', [
            'shop' => $shop,
            'cash' => (float) $shop->cash_balance,
            'bank' => (float) $shop->bank_balance,
            'stockValue' => $stockValue,
            'receivable' => $receivable,
            'codReceivable' => $codReceivable,
            'payable' => $payable,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'netWorth' => $netWorth,
            'capital' => (float) $shop->capital,
            'retained' => $netWorth - (float) $shop->capital,
            'totalExpenses' => (float) Expense::sum('amount'),
            'totalDamage' => (float) Damage::sum('loss'),
            'totalReturns' => (float) SalesReturn::onLiveBills()->sum('refund'),
            // this month's profit, split offline / online, for the little summary card (the full page is accounts.profit)
            'profitMonth' => Reports::profitByChannel(now()->startOfMonth()->toDateString(), now()->toDateString()),
            'hasOnline' => (bool) $shop->hasFeature('online_business'),
        ]);
    }

    /**
     * Profit, split the way the owner thinks about it: what the counter (offline) made, what online orders made,
     * and the total — then every product with the same three columns. Same numbers as the P&L (see
     * Reports::profitByChannel), just cut by channel.
     */
    public function profit(Request $request)
    {
        $preset = in_array($request->get('preset'), ['today', 'week', 'month', 'year', 'custom'], true) ? $request->get('preset') : 'month';
        [$from, $to] = match ($preset) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->subDays(6)->toDateString(), now()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->toDateString()],
            'custom' => [$request->get('from') ?: now()->subDays(29)->toDateString(), $request->get('to') ?: now()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->toDateString()],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $products = Reports::productProfitByChannel($from, $to);

        return Inertia::render('App/Accounts/ProfitSplit', [
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'split' => Reports::profitByChannel($from, $to),
            'products' => $products['rows'],
            'unallocatedDiscount' => $products['unallocated_discount'],
            'hasOnline' => (bool) Tenancy::shop()?->hasFeature('online_business'),
        ]);
    }

    /**
     * What the stock on the shelves cost to buy.
     *
     * This was SUM(products.cost * products.stock) — the article's one
     * headline cost against its total stock. A size can now be bought at
     * its own price (SizeGroupPricing), and ProductVariant::
     * effectiveCost(), which POS checkout, returns, damages and every
     * profit figure already go through, prefers that over the article's.
     * This page did not, so a shop pricing by size group valued its own
     * shelves wrong — measured on Baby Park's branch, ৳526,675 against a
     * true ৳544,825.
     *
     * Two halves because a variant's own stock is the real count once a
     * product has any: products.stock is kept as their sum, so adding
     * both would count every pair twice. A product with no live variants
     * is still valued the old way, which is exactly right for it.
     */
    /**
     * The stock value, serial by serial — what the one figure on the
     * Accounts page is MADE of. Khaled: "serail dhore kontar stock mullo
     * koto ache ... stock mulle click korle details e jate ese jai."
     *
     * Same arithmetic as stockValue() — COALESCE(variant cost, article
     * cost) × stock per size, article cost × stock for a plain product —
     * applied row by row instead of in one SUM, so the detail's grand
     * total and the card can never disagree.
     */
    public function stockValueDetail()
    {
        // one valuation for the whole app (StockValuation): a lot-tracked shop values each lot at its own cost, any
        // other shop stock x current cost — so this page's total is always the Accounts card's figure
        $rows = StockValuation::articles()
            ->map(fn (array $a) => [
                'name' => $a['name'],
                'emoji' => $a['emoji'],
                'qty' => round((float) collect($a['sizes'])->filter(fn ($s) => $a['has_variants'] ? $s['qty'] != 0 : true)->sum('qty'), 3),
                'value' => round((float) collect($a['sizes'])->filter(fn ($s) => $a['has_variants'] ? $s['qty'] != 0 : true)->sum('value'), 2),
                'variants' => $a['has_variants']
                    ? collect($a['sizes'])->filter(fn ($s) => $s['qty'] != 0)->map(fn ($s) => [
                        'label' => $s['label'],
                        'qty' => $s['qty'],
                        'unit_cost' => round($s['unit_cost'], 2),
                        'value' => round($s['value'], 2),
                    ])->values()->all()
                    : [],
            ])
            ->filter(fn ($r) => $r['qty'] != 0 || $r['value'] != 0)
            ->sortByDesc('value')->values();

        $shop = Tenancy::shop();

        return Inertia::render('App/Accounts/StockValue', [
            'rows' => $rows->all(),
            'total' => round((float) $rows->sum('value'), 2),
            'totalQty' => round((float) $rows->sum('qty'), 3),
            'supplierView' => (bool) ($shop && ($shop->hasFeature('suppliers') || $shop->hasFeature('source_lines'))),
        ]);
    }

    /**
     * The same stock value, split by WHO the goods came from. Khaled (Baby
     * Park): "oi stock e kon supplier tekhe koto tk er product nicche eta
     * dekhate hobe details."
     *
     * Two sources of truth, most precise first: a shop on `source_lines`
     * has supplier lots (ProductSource: remaining qty × lot cost); every
     * other shop only knows an article's supplier (products.supplier_id),
     * so the article's whole value goes to that supplier. Whatever neither
     * covers is shown honestly as "supplier unknown" — never guessed.
     *
     * Under shared_stock_pool the stock is ONE figure the branches all
     * show, but lots are per shop (each branch's lots are what IT received,
     * depleted by ITS sales), so lots are summed across every real member
     * of the business; a branch copy's supplier is its master's (branch
     * copies are never stamped with one). The result is therefore the same
     * on every branch, same as the stock value card itself.
     */
    /**
     * The window for the supplier report's flow figures (received / sold).
     * Same query-param contract as the Online Orders dashboard:
     * period=today|7d|month|all|custom, custom taking from=Y-m-d[&to=Y-m-d]
     * (to omitted = that one day, swapped if reversed). 'all' is the
     * default here — an owner asking "how much did this supplier give me"
     * means ever. Anything unparseable falls back to 'all'.
     *
     * @return array{0: ?\Carbon\Carbon, 1: ?\Carbon\Carbon, 2: string}
     */
    private function flowWindow(Request $request): array
    {
        $period = (string) $request->query('period', 'all');
        $today = now()->startOfDay();

        if ($period === 'today') {
            return [$today, $today->copy()->endOfDay(), 'today'];
        }
        if ($period === '7d') {
            return [$today->copy()->subDays(6), $today->copy()->endOfDay(), '7d'];
        }
        if ($period === 'month') {
            return [$today->copy()->startOfMonth(), $today->copy()->endOfDay(), 'month'];
        }
        if ($period === 'custom') {
            $parse = function ($v) {
                if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    return null;
                }
                $d = \Carbon\Carbon::createFromFormat('Y-m-d', $v)->startOfDay();

                return $d->format('Y-m-d') === $v ? $d : null;
            };
            $from = $parse($request->query('from'));
            if ($from) {
                $to = $parse($request->query('to')) ?? $from->copy();
                if ($to->lt($from)) {
                    [$from, $to] = [$to, $from];
                }

                return [$from, $to->endOfDay(), 'custom'];
            }
        }

        return [null, null, 'all'];
    }

    /**
     * Per-supplier RECEIVED (stock_intakes) and SOLD (sale_items) figures,
     * business-wide for a pooled group. The supplier of a row is, in order:
     * the intake's own supplier_id, the MASTER article's supplier (a branch
     * copy carries none), the article's own; none of them = unknown.
     *
     * @param  array<int,int>  $memberIds
     * @return array{received: array, sold: array}  keyed by supplier id or 'unknown'
     */
    private function supplierFlows(array $memberIds, ?\Carbon\Carbon $from, ?\Carbon\Carbon $to): array
    {
        $received = DB::table('stock_intakes as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('products as m', 'm.id', '=', 'p.master_product_id')
            ->whereIn('i.shop_id', $memberIds)
            ->when($from, fn ($q) => $q->where('i.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('i.created_at', '<=', $to))
            ->selectRaw('COALESCE(i.supplier_id, m.supplier_id, p.supplier_id) as sid, SUM(i.qty) as qty, SUM(i.total_cost) as cost')
            ->groupBy('sid')->get()
            ->mapWithKeys(fn ($r) => [($r->sid ?: 'unknown') => ['qty' => (float) $r->qty, 'cost' => (float) $r->cost]])->all();

        // exactly the bills Reports counts: not voided, counted_in_reports
        // (an online order only once delivered)
        $soldRows = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('products as m', 'm.id', '=', 'p.master_product_id')
            ->whereIn('s.shop_id', $memberIds)
            ->whereNull('s.voided_at')->where('s.counted_in_reports', true)
            ->when($from, fn ($q) => $q->whereDate('s.date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('s.date', '<=', $to->toDateString()))
            ->selectRaw('COALESCE(m.supplier_id, p.supplier_id) as sid, s.shop_id as sale_shop, SUM(si.qty) as qty, SUM(si.qty * si.price - si.discount) as value, SUM(si.qty * si.cost) as cost')
            ->groupBy('sid', 'sale_shop')->get();

        $sold = [];
        foreach ($soldRows as $r) {
            $key = $r->sid ?: 'unknown';
            $sold[$key] ??= ['qty' => 0.0, 'value' => 0.0, 'cost' => 0.0, 'branches' => []];
            $sold[$key]['qty'] += (float) $r->qty;
            $sold[$key]['value'] += (float) $r->value;
            $sold[$key]['cost'] += (float) $r->cost;
            $sold[$key]['branches'][(int) $r->sale_shop] = ['qty' => (float) $r->qty, 'value' => (float) $r->value];
        }

        return ['received' => $received, 'sold' => $sold];
    }

    public function stockValueBySupplier(Request $request)
    {
        $shop = Tenancy::shop();
        abort_unless($shop && ($shop->hasFeature('suppliers') || $shop->hasFeature('source_lines')), 404);

        // The same valuation as the Accounts card (StockValuation), cut by supplier: a lot is valued at its own cost and
        // belongs to the lot's supplier; whatever no lot covers belongs to the article's supplier (its master's, for a
        // branch copy) — "unknown" only when there is truly no supplier on record. Under a shared stock pool the lots of
        // every branch are summed, so the figure is the same on each branch, like the card itself.
        $memberIds = StockValuation::memberIds($shop->id);
        $usesLots = false;

        $supplierNames = Supplier::withoutGlobalScopes()->whereIn('shop_id', $memberIds)->pluck('name', 'id');
        $bySupplier = []; // key => ['name', 'qty', 'value', 'products' => []]
        $add = function ($key, string $name, array $article, float $qty, float $value, string $via) use (&$bySupplier) {
            if ($value <= 0 && $qty <= 0) {
                return;
            }
            $bySupplier[$key] ??= ['id' => is_int($key) ? $key : null, 'name' => $name, 'qty' => 0.0, 'value' => 0.0, 'products' => []];
            $bySupplier[$key]['qty'] += $qty;
            $bySupplier[$key]['value'] += $value;
            $bySupplier[$key]['products'][] = ['name' => $article['name'], 'emoji' => $article['emoji'], 'qty' => round($qty, 3), 'value' => round($value, 2), 'via' => $via];
        };

        foreach (StockValuation::articles($shop->id) as $article) {
            foreach ($article['parts'] as $part) {
                $supplierId = $part['supplier_id'];
                $usesLots = $usesLots || $part['via'] === 'lot';
                $add($supplierId ?: 'unknown', $supplierId ? ($supplierNames[$supplierId] ?? "#{$supplierId}") : '', $article, (float) $part['qty'], (float) $part['value'], $part['via']);
            }
        }


        // received / sold in the chosen window — a supplier with goods
        // received or sold but nothing left on the shelf still gets a row
        [$from, $to, $period] = $this->flowWindow($request);
        $flows = $this->supplierFlows($memberIds, $from, $to);
        foreach (array_unique(array_merge(array_keys($flows['received']), array_keys($flows['sold']))) as $key) {
            $bySupplier[$key] ??= [
                'id' => is_int($key) ? $key : null,
                'name' => is_int($key) ? ($supplierNames[$key] ?? "#{$key}") : '',
                'qty' => 0.0, 'value' => 0.0, 'products' => [],
            ];
        }
        $branchNames = Shop::withoutGlobalScopes()->whereIn('id', $memberIds)->pluck('name', 'id');

        $rows = collect($bySupplier)->map(function ($s, $key) use ($flows, $branchNames) {
            $sold = $flows['sold'][$key] ?? null;
            $received = $flows['received'][$key] ?? null;

            return [
                'id' => $s['id'],
                'name' => $key === 'unknown' ? null : $s['name'],
                'unknown' => $key === 'unknown',
                'qty' => round($s['qty'], 3),
                'value' => round($s['value'], 2),
                'products' => collect($s['products'])->sortByDesc('value')->values()->all(),
                'received' => round($received['cost'] ?? 0, 2),
                'receivedQty' => round($received['qty'] ?? 0, 3),
                // "All time" must not be answered from the intake ledger, which only
                // starts 2026-11-04 (a supplier showed "received 27 pcs" beside 366 on
                // the shelf). Everything that came in is either still here or was sold,
                // so for the whole history: what is on the shelf now + what was sold.
                'everQty' => round($s['qty'] + ($sold['qty'] ?? 0), 3),
                'everCost' => round($s['value'] + ($sold['cost'] ?? 0), 2),
                'sold' => round($sold['value'] ?? 0, 2),
                'soldCost' => round($sold['cost'] ?? 0, 2),
                'soldQty' => round($sold['qty'] ?? 0, 3),
                'branches' => collect($sold['branches'] ?? [])->map(fn ($b, $shopId) => [
                    'shop_id' => $shopId,
                    'name' => $branchNames[$shopId] ?? "#{$shopId}",
                    'value' => round($b['value'], 2),
                    'qty' => round($b['qty'], 3),
                ])->sortByDesc('value')->values()->all(),
            ];
        })->sortBy([['unknown', 'asc'], ['value', 'desc'], ['sold', 'desc']])->values();

        return Inertia::render('App/Accounts/StockValueBySupplier', [
            'rows' => $rows->all(),
            'total' => round($this->stockValue(), 2),
            'pooledWith' => count($memberIds) > 1
                ? Shop::withoutGlobalScopes()->whereIn('id', $memberIds)->where('id', '!=', $shop->id)->pluck('name')->all()
                : [],
            'usesLots' => $usesLots,
            'period' => $period,
            'from' => $period === 'custom' ? $from?->toDateString() : null,
            'to' => $period === 'custom' ? $to?->toDateString() : null,
            // "received" is only as old as the intake ledger: say where it starts
            'receivedSince' => DB::table('stock_intakes')->whereIn('shop_id', $memberIds)->min('created_at'),
            'totals' => [
                'received' => round(collect($flows['received'])->sum('cost'), 2),
                'everCost' => round($rows->sum('everCost'), 2),
                'sold' => round(collect($flows['sold'])->sum('value'), 2),
                'soldCost' => round(collect($flows['sold'])->sum('cost'), 2),
            ],
        ]);
    }

    /** the stock value card — one definition for the whole app, see StockValuation */
    private function stockValue(): float
    {
        return StockValuation::total();
    }
}
