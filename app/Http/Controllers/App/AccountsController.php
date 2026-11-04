<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Expense;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesReturn;
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

        return Inertia::render('App/Accounts/StockValue', [
            'rows' => $all->all(),
            'total' => round((float) $all->sum('value'), 2),
            'totalQty' => round((float) $all->sum('qty'), 3),
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
