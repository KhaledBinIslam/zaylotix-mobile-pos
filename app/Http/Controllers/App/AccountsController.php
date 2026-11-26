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
            'totalReturns' => (float) SalesReturn::sum('refund'),
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
        $variantProducts = Product::whereHas('variants')
            ->with(['variants' => fn ($q) => $q->orderBy('color')->orderBy('size')])
            ->get(['id', 'name', 'name_en', 'emoji', 'cost']);

        $rows = $variantProducts->map(function (Product $p) {
            $variants = $p->variants->map(fn (ProductVariant $v) => [
                'label' => trim(implode(', ', array_filter([$v->size, $v->color]))) ?: '—',
                'qty' => (float) $v->stock,
                'unit_cost' => (float) ($v->cost ?? $p->cost),
                'value' => round((float) ($v->cost ?? $p->cost) * (float) $v->stock, 2),
            ])->filter(fn ($v) => $v['qty'] != 0)->values();

            return [
                'name' => $p->name,
                'emoji' => $p->emoji,
                'qty' => round((float) $variants->sum('qty'), 3),
                'value' => round((float) $variants->sum('value'), 2),
                'variants' => $variants->all(),
            ];
        });

        $plainRows = Product::whereDoesntHave('variants')
            ->where('stock', '!=', 0)
            ->get(['id', 'name', 'emoji', 'cost', 'stock'])
            ->map(fn (Product $p) => [
                'name' => $p->name,
                'emoji' => $p->emoji,
                'qty' => (float) $p->stock,
                'value' => round((float) $p->cost * (float) $p->stock, 2),
                'variants' => [],
            ]);

        $all = $rows->concat($plainRows)
            ->filter(fn ($r) => $r['qty'] != 0 || $r['value'] != 0)
            ->sortByDesc('value')->values();

        $shop = Tenancy::shop();

        return Inertia::render('App/Accounts/StockValue', [
            'rows' => $all->all(),
            'total' => round((float) $all->sum('value'), 2),
            'totalQty' => round((float) $all->sum('qty'), 3),
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

        $rootId = $shop->parent_shop_id ?? $shop->id;
        $memberIds = $shop->hasFeature('shared_stock_pool') || Shop::withoutGlobalScopes()->find($rootId)?->hasFeature('shared_stock_pool')
            ? Shop::withoutGlobalScopes()->where('account_type', '!=', 'demo')
                ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_shop_id', $rootId))->pluck('id')->all()
            : [$shop->id];

        // this shop's own rows carry the (pooled) figure — valued exactly as stockValue() does
        $products = Product::with(['variants' => fn ($q) => $q->select('id', 'product_id', 'size', 'color', 'stock', 'cost')])
            ->get(['id', 'name', 'emoji', 'cost', 'stock', 'supplier_id', 'master_product_id']);

        $masterSupplier = Product::withoutGlobalScopes()
            ->whereIn('id', $products->pluck('master_product_id')->filter()->unique())
            ->pluck('supplier_id', 'id');

        // lots across every member, keyed by pool group (master id, or the product's own id)
        $groupOf = $products->mapWithKeys(fn (Product $p) => [$p->id => $p->master_product_id ?? $p->id]);
        $lotsByGroup = collect();
        if (count($memberIds) > 1 || $shop->hasFeature('source_lines')) {
            $memberProducts = Product::withoutGlobalScopes()->whereIn('shop_id', $memberIds)->whereNull('deleted_at')
                ->whereIn(\Illuminate\Support\Facades\DB::raw('COALESCE(master_product_id, id)'), $groupOf->values()->unique())
                ->get(['id', 'master_product_id']);
            $memberGroup = $memberProducts->mapWithKeys(fn (Product $p) => [$p->id => $p->master_product_id ?? $p->id]);
            $lots = ProductSource::withoutGlobalScopes()->whereIn('shop_id', $memberIds)->whereNull('deleted_at')
                ->where('stock', '>', 0)->whereIn('product_id', $memberGroup->keys())
                ->get(['product_id', 'supplier_id', 'stock', 'cost']);
            $lotsByGroup = $lots->groupBy(fn ($l) => $memberGroup[$l->product_id] ?? 0);
        }

        $supplierNames = Supplier::withoutGlobalScopes()->whereIn('shop_id', $memberIds)->pluck('name', 'id');
        $bySupplier = []; // key => ['name', 'qty', 'value', 'products' => []]
        $add = function ($key, string $name, Product $p, float $qty, float $value, string $via) use (&$bySupplier) {
            if ($value <= 0 && $qty <= 0) {
                return;
            }
            $bySupplier[$key] ??= ['id' => is_int($key) ? $key : null, 'name' => $name, 'qty' => 0.0, 'value' => 0.0, 'products' => []];
            $bySupplier[$key]['qty'] += $qty;
            $bySupplier[$key]['value'] += $value;
            $bySupplier[$key]['products'][] = ['name' => $p->name, 'emoji' => $p->emoji, 'qty' => round($qty, 3), 'value' => round($value, 2), 'via' => $via];
        };

        foreach ($products as $p) {
            $hasVariants = $p->variants->isNotEmpty();
            $qty = $hasVariants ? (float) $p->variants->sum('stock') : (float) $p->stock;
            $value = $hasVariants
                ? (float) $p->variants->sum(fn ($v) => (float) ($v->cost ?? $p->cost) * (float) $v->stock)
                : (float) $p->cost * (float) $p->stock;

            $covered = 0.0;
            $coveredQty = 0.0;
            // The lots' own ledger can run AHEAD of the article's stock (a stock
            // correction that never touched the lots: SEAMAN's Bideshi Boiler Suit
            // had 22 pcs on the shelf and 46 in its lots). Counting the lots in
            // full then pushed the report ৳25,000 above the real stock value, found
            // by checking every paid shop's totals against stockValue(). The
            // report's total is the card's total, so lots are scaled down to what
            // the article really holds, pro rata across its suppliers.
            $articleLots = $lotsByGroup->get($groupOf[$p->id]) ?? collect();
            $lotsValueAll = (float) $articleLots->sum(fn ($l) => (float) $l->stock * (float) $l->cost);
            $lotsQtyAll = (float) $articleLots->sum('stock');
            $valueScale = $lotsValueAll > $value && $lotsValueAll > 0 ? $value / $lotsValueAll : 1.0;
            $qtyScale = $lotsQtyAll > $qty && $lotsQtyAll > 0 ? $qty / $lotsQtyAll : 1.0;
            foreach ($articleLots->groupBy('supplier_id') as $supplierId => $lots) {
                $lotValue = (float) $lots->sum(fn ($l) => (float) $l->stock * (float) $l->cost) * $valueScale;
                $lotQty = (float) $lots->sum('stock') * $qtyScale;
                $key = $supplierId ? (int) $supplierId : 'unknown';
                $add($key, $supplierId ? ($supplierNames[$supplierId] ?? "#{$supplierId}") : '', $p, $lotQty, $lotValue, 'lot');
                $covered += $lotValue;
                $coveredQty += $lotQty;
            }

            $rest = max(0.0, $value - $covered);
            $restQty = max(0.0, $qty - $coveredQty);
            if ($rest > 0 || $restQty > 0) {
                $supplierId = $p->master_product_id ? ($masterSupplier[$p->master_product_id] ?? $p->supplier_id) : $p->supplier_id;
                $key = $supplierId ? (int) $supplierId : 'unknown';
                $add($key, $supplierId ? ($supplierNames[$supplierId] ?? "#{$supplierId}") : '', $p, $restQty, $rest, 'article');
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
            'usesLots' => $lotsByGroup->isNotEmpty(),
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

    private function stockValue(): float
    {
        $fromVariants = (float) ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->selectRaw('COALESCE(SUM(COALESCE(product_variants.cost, products.cost) * product_variants.stock), 0) as v')
            ->value('v');

        $fromPlainProducts = (float) Product::whereDoesntHave('variants')
            ->selectRaw('COALESCE(SUM(cost * stock), 0) as v')->value('v');

        return $fromVariants + $fromPlainProducts;
    }
}
