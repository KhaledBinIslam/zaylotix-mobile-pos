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
use App\Support\Tenancy;
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
    public function stockValueBySupplier()
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
            foreach (($lotsByGroup->get($groupOf[$p->id]) ?? collect())->groupBy('supplier_id') as $supplierId => $lots) {
                $lotValue = (float) $lots->sum(fn ($l) => (float) $l->stock * (float) $l->cost);
                $lotQty = (float) $lots->sum('stock');
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

        $rows = collect($bySupplier)->map(fn ($s, $key) => [
            'id' => $s['id'],
            'name' => $key === 'unknown' ? null : $s['name'],
            'unknown' => $key === 'unknown',
            'qty' => round($s['qty'], 3),
            'value' => round($s['value'], 2),
            'products' => collect($s['products'])->sortByDesc('value')->values()->all(),
        ])->sortBy([['unknown', 'asc'], ['value', 'desc']])->values();

        return Inertia::render('App/Accounts/StockValueBySupplier', [
            'rows' => $rows->all(),
            'total' => round($this->stockValue(), 2),
            'pooledWith' => count($memberIds) > 1
                ? Shop::withoutGlobalScopes()->whereIn('id', $memberIds)->where('id', '!=', $shop->id)->pluck('name')->all()
                : [],
            'usesLots' => $lotsByGroup->isNotEmpty(),
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
