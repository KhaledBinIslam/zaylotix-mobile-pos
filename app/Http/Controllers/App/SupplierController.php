<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use Illuminate\Http\Request;
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

        return Inertia::render('App/Suppliers/Index', [
            'suppliers' => $withPayable->concat($settled)->values(),
        ]);
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

        return Inertia::render('App/Suppliers/Show', [
            'supplier' => $supplier,
            'purchases' => $purchases,
            'returns' => $returns,
            'payments' => $payments,
            'totals' => [
                'purchased' => (float) $purchases->where('status', 'received')->sum('amount'),
                'returned' => (float) $returns->sum('amount'),
                'paid' => (float) $payments->sum('amount'),
                'payable' => (float) $supplier->payable,
            ],
        ]);
    }
}
