<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\StockIn;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Phase 4 item #2 — "real PO/GRN" (see docs/FIX_PLAN.md's design note). A
 * wholly new, parallel system alongside the existing single-line Purchase
 * flow — placing a PO moves no stock or money; only receive() does,
 * proportional to what's actually received each time.
 */
class PurchaseOrderController extends Controller
{
    public function index()
    {
        return Inertia::render('App/PurchaseOrders/Index', [
            'purchaseOrders' => PurchaseOrder::with('supplier:id,name')
                ->withCount('items')
                ->latest('id')->limit(100)->get(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'products' => Product::with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])
                ->orderBy('name')
                ->get(['id', 'name', 'emoji', 'cost'])
                ->map(fn (Product $p) => [
                    'id' => $p->id, 'name' => $p->name, 'emoji' => $p->emoji, 'cost' => (float) $p->cost,
                    'variants' => $p->variants->map(fn (ProductVariant $v) => [
                        'id' => $v->id, 'label' => $v->label(), 'cost' => (float) ($v->cost ?? $p->cost),
                    ]),
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('shop_id', Tenancy::id())],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'items.*.product_variant_id' => ['nullable', Rule::exists('product_variants', 'id')->where('shop_id', Tenancy::id())],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.cost' => ['required', 'numeric', 'min:0'],
        ]);

        $shopId = Tenancy::id();

        $po = DB::transaction(function () use ($data, $shopId) {
            // locking the shop row serializes concurrent PO submissions so
            // the sequential po_number can't collide — same pattern as
            // Sale's invoice_no / Quotation's quote_no
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            $subtotal = 0.0;
            $lines = [];
            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $variant = ! empty($item['product_variant_id']) ? ProductVariant::where('product_id', $product->id)->find($item['product_variant_id']) : null;
                if (! empty($item['product_variant_id']) && ! $variant) {
                    throw ValidationException::withMessages(['items' => 'এই ভ্যারিয়েন্টটি সঠিক নয়।']);
                }

                $qty = (float) $item['qty'];
                $cost = (float) $item['cost'];
                $subtotal += $qty * $cost;

                $lines[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'variant_label' => $variant?->label(),
                    'qty_ordered' => $qty,
                    'cost' => $cost,
                ];
            }

            $shop->po_counter += 1;
            $poNumber = 'PO-'.$shop->po_counter;
            $shop->save();

            $po = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'po_number' => $poNumber,
                'date' => now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'status' => 'ordered',
                'subtotal' => $subtotal,
                'notes' => $data['notes'] ?? null,
                'user_id' => auth('web')->id() ?? auth('sanctum')->id(),
            ]);

            foreach ($lines as $line) {
                PurchaseOrderItem::create(['purchase_order_id' => $po->id, ...$line]);
            }

            return $po;
        });

        Activity::log('purchaseOrder.create', "নতুন পারচেজ অর্ডার {$po->po_number} তৈরি হয়েছে — ".number_format($po->subtotal, 2).' টাকা।', $po);

        return redirect()->route('app.purchaseOrders.show', $po)->with('success', "পারচেজ অর্ডার {$po->po_number} তৈরি হয়েছে।");
    }

    public function show(PurchaseOrder $po)
    {
        $po->load(['supplier:id,name,phone', 'items', 'user:id,name']);

        return Inertia::render('App/PurchaseOrders/Show', ['po' => $po]);
    }

    /**
     * The actual GRN — never more than what's still outstanding per line.
     * One lump cash/bank decrement or supplier-payable increase for the
     * whole receipt event, not one per line, matching how a real goods
     * receipt against a supplier invoice is a single transaction.
     */
    public function receive(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate([
            'method' => ['required', 'in:cash,bank,credit'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $po->id)],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        DB::transaction(function () use ($data, $po) {
            $lockedPo = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
            if (in_array($lockedPo->status, ['received', 'cancelled'], true)) {
                abort(422, 'এই অর্ডারটি আর গ্রহণ করা যাবে না।');
            }

            $receivedAmount = 0.0;

            foreach ($data['items'] as $line) {
                $item = PurchaseOrderItem::whereKey($line['id'])->lockForUpdate()->first();
                $qty = (float) $line['qty'];

                if ($qty > $item->remainingQty() + 0.001) {
                    throw ValidationException::withMessages(['items' => "{$item->product_name}-এর জন্য অর্ডার করা পরিমাণের চেয়ে বেশি গ্রহণ করা যাবে না (বাকি আছে {$item->remainingQty()})।"]);
                }

                if ($item->product_variant_id) {
                    $variant = ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first();
                    if ($variant) {
                        $variant->update(['stock' => $variant->stock + $qty, 'cost' => $item->cost]);
                        Product::whereKey($item->product_id)->increment('stock', $qty);
                    }
                } elseif ($item->product_id) {
                    $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                    if ($product) {
                        StockIn::apply($product, $qty, (float) $item->cost);
                    }
                }

                $item->increment('qty_received', $qty);
                $receivedAmount += $qty * (float) $item->cost;
            }

            if ($receivedAmount > 0) {
                if ($data['method'] === 'cash' || $data['method'] === 'bank') {
                    $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
                    Shop::whereKey($lockedPo->shop_id)->decrement($field, $receivedAmount);
                } else {
                    Supplier::whereKey($lockedPo->supplier_id)->increment('payable', $receivedAmount);
                }
            }

            $freshItems = PurchaseOrderItem::where('purchase_order_id', $lockedPo->id)->get();
            $allReceived = $freshItems->every(fn (PurchaseOrderItem $i) => $i->remainingQty() <= 0.001);
            $anyReceived = $freshItems->contains(fn (PurchaseOrderItem $i) => (float) $i->qty_received > 0);
            $lockedPo->update(['status' => $allReceived ? 'received' : ($anyReceived ? 'partially_received' : 'ordered')]);

            Activity::log('purchaseOrder.receive', "পারচেজ অর্ডার {$lockedPo->po_number}-এ ".number_format($receivedAmount, 2).' টাকার মাল গ্রহণ করা হয়েছে।', $lockedPo, [
                'amount' => $receivedAmount, 'method' => $data['method'],
            ]);
        });

        return back()->with('success', 'গ্রহণ করা হয়েছে — স্টক/ব্যালেন্স হালনাগাদ হয়েছে।');
    }

    /** Only while nothing has been received yet — once any stock/money has moved, the order must be seen through or dealt with via a return, not silently erased. */
    public function cancel(PurchaseOrder $po)
    {
        DB::transaction(function () use ($po) {
            $locked = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
            if ($locked->status !== 'ordered') {
                abort(422, 'এই অর্ডারে ইতিমধ্যে কিছু গ্রহণ করা হয়েছে — এখন বাতিল করা যাবে না।');
            }

            $locked->update(['status' => 'cancelled']);
            Activity::log('purchaseOrder.cancel', "পারচেজ অর্ডার {$locked->po_number} বাতিল করা হয়েছে।", $locked);
        });

        return back()->with('success', 'পারচেজ অর্ডার বাতিল করা হয়েছে।');
    }
}
