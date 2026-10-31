<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function index()
    {
        // same reasoning as CustomerController::index — every supplier you
        // owe money to must stay fully visible, only the settled/reference
        // half of the list is capped.
        $withPayable = Supplier::where('payable', '>', 0)->orderByDesc('payable')->get();
        $settled = Supplier::where('payable', '<=', 0)->orderByDesc('updated_at')->limit(300)->get();

        // Phase 47 (Baby Park) — DERIVED per-supplier intake/sales stats:
        // the footwear article flow already stamps supplier_id + cost on
        // every product at entry, so "what came in from whom, and whose
        // products sell" needs no separate purchase entry at all — two
        // grouped aggregates over existing rows, merged in by id. Intake
        // value is cost × (current stock + units sold): an approximation of
        // everything ever received (damages/supplier-returns ignored),
        // which is exactly the overview Khaled asked for, not accounting.
        $stockAgg = Product::whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) as products_count, COALESCE(SUM(cost * stock), 0) as stock_value')
            ->get()->keyBy('supplier_id');

        $soldAgg = SaleItem::join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('products.shop_id', Tenancy::id()) // the join bypasses Product's own tenant scope
            ->whereNotNull('products.supplier_id')
            ->groupBy('products.supplier_id')
            ->selectRaw('products.supplier_id, COALESCE(SUM(sale_items.qty), 0) as sold_units, COALESCE(SUM(sale_items.price * sale_items.qty - sale_items.discount), 0) as sold_revenue, COALESCE(SUM(sale_items.cost * sale_items.qty), 0) as sold_cost')
            ->get()->keyBy('supplier_id');

        $suppliers = $withPayable->concat($settled)->values()->map(function ($s) use ($stockAgg, $soldAgg) {
            $st = $stockAgg->get($s->id);
            $so = $soldAgg->get($s->id);
            $arr = $s->toArray();
            $arr['stats'] = [
                'products' => (int) ($st->products_count ?? 0),
                'stock_value' => (float) ($st->stock_value ?? 0),
                'sold_units' => (float) ($so->sold_units ?? 0),
                'sold_revenue' => (float) ($so->sold_revenue ?? 0),
                'intake_value' => (float) ($st->stock_value ?? 0) + (float) ($so->sold_cost ?? 0),
            ];

            return $arr;
        });

        return Inertia::render('App/Suppliers/Index', [
            'suppliers' => $suppliers,
        ]);
    }

    /**
     * Phase 47 — owner-only manual due: Khaled's explicit design for the
     * article flow is that intake is TRACKED automatically (derived stats
     * above) but money owed is never auto-created — "due thakle manually
     * owner chaile tulte pare, na tulle nei". Recorded as a product-less
     * 'credit' Purchase row, which is the exact pre-existing semantic for
     * "took goods, owe money": it shows up in the supplier's purchase
     * history and the payable settles through the normal payments flow.
     */
    public function addDue(Request $request, Supplier $supplier)
    {
        $this->authorize('update', $supplier);
        abort_unless($request->user()->role === 'owner', 403, 'শুধু মালিক বাকি যোগ করতে পারেন।');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'memo' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($supplier, $data) {
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->first();

            Purchase::create([
                'supplier_id' => $locked->id,
                'supplier' => $locked->name,
                'memo' => $data['memo'],
                'amount' => $data['amount'],
                'method' => 'credit',
                'status' => 'received',
                'date' => now()->toDateString(),
            ]);
            $locked->increment('payable', $data['amount']);

            Activity::log('supplier.dueAdded', "সাপ্লায়ার '{$locked->name}'-এর বাকিতে ".number_format((float) $data['amount'], 2).' টাকা যোগ করা হয়েছে ('.$data['memo'].')।', $locked, ['amount' => $data['amount']]);
        });

        return back()->with('success', 'বাকি যোগ করা হয়েছে।');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = $data['phone'] ?? null;
        $existing = $phone ? Supplier::where('phone', $phone)->first() : null;

        if ($existing) {
            return back()->withErrors([
                'phone' => "এই নম্বরে আগে থেকেই সাপ্লায়ার আছে: {$existing->name}। নতুন করে যোগ করার দরকার নেই।",
            ]);
        }

        Supplier::create([
            'name' => $data['name'],
            'phone' => $phone,
            'address' => $data['address'] ?? null,
        ]);

        return back()->with('success', "{$data['name']} added.");
    }

    /**
     * Phase 4.6 (see docs/FIX_PLAN.md) — confirmed missing from scratch:
     * there was no way to see a supplier's purchase-by-purchase history,
     * returns, or payment history anywhere — only the running payable
     * total on the list page. total_purchased only counts 'received'
     * purchases (a 'pending' order hasn't actually happened yet).
     */
    public function show(Supplier $supplier)
    {
        $this->authorize('view', $supplier);

        $purchases = $supplier->purchases()
            ->with('product:id,name,emoji')
            ->orderByDesc('date')->orderByDesc('id')
            ->get(['id', 'supplier_id', 'amount', 'method', 'status', 'date', 'product_id', 'qty', 'memo']);

        $returns = SupplierReturn::where('supplier_id', $supplier->id)
            ->with('product:id,name,emoji')
            ->orderByDesc('date')->orderByDesc('id')
            ->get(['id', 'supplier_id', 'product_id', 'qty', 'amount', 'settlement_method', 'reason', 'date']);

        $payments = $supplier->supplierPayments()
            ->with('user:id,name')
            ->orderByDesc('date')->orderByDesc('id')
            ->get(['id', 'supplier_id', 'user_id', 'amount', 'payable_before', 'payable_after', 'method', 'date']);

        // Phase 47 — the supplier's own products (auto-linked at article
        // entry via products.supplier_id), each with lifetime sold figures:
        // "oi supplier theke ki ki product in hocche" with zero manual entry.
        $products = Product::where('supplier_id', $supplier->id)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'name', 'emoji', 'barcode', 'cost', 'price', 'stock', 'created_at']);

        $soldByProduct = $products->isEmpty()
            ? collect()
            : SaleItem::whereIn('product_id', $products->pluck('id'))
                ->groupBy('product_id')
                ->selectRaw('product_id, COALESCE(SUM(qty), 0) as units, COALESCE(SUM(price * qty - discount), 0) as revenue')
                ->get()->keyBy('product_id');

        $productRows = $products->map(function ($p) use ($soldByProduct) {
            $sold = $soldByProduct->get($p->id);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'emoji' => $p->emoji,
                'barcode' => $p->barcode,
                'cost' => (float) $p->cost,
                'stock' => (float) $p->stock,
                'added_on' => $p->created_at?->toDateString(),
                'sold_units' => (float) ($sold->units ?? 0),
                'sold_revenue' => (float) ($sold->revenue ?? 0),
                'intake_value' => (float) $p->cost * ((float) $p->stock + (float) ($sold->units ?? 0)),
            ];
        });

        return Inertia::render('App/Suppliers/Show', [
            'supplier' => $supplier,
            'purchases' => $purchases,
            'returns' => $returns,
            'payments' => $payments,
            'products' => $productRows,
            'totals' => [
                'purchased' => (float) $purchases->where('status', 'received')->sum('amount'),
                'returned' => (float) $returns->sum('amount'),
                'paid' => (float) $payments->sum('amount'),
                'payable' => (float) $supplier->payable,
            ],
        ]);
    }
}
