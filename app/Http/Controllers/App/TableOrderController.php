<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * A restaurant's order-then-bill flow, distinct from PosController's instant
 * checkout: items get added to an open tab over time (stock leaves as each
 * is ordered, not deferred to billing), and only bill() creates the real
 * Sale/SaleItem rows — from that point on it's a completely ordinary sale,
 * voidable and reportable with no special-casing anywhere else in the app.
 *
 * Every mutating action re-locks and re-checks the order's status *inside*
 * the transaction (not just before it) — a plain pre-check would leave a
 * window for a double-tapped "bill"/"cancel" request (or a retried one) to
 * race past the check twice and create two Sales, or double-restore stock.
 */
class TableOrderController extends Controller
{
    public function show(TableOrder $tableOrder)
    {
        // scoped to whereNull('sale_id') — an already-split-billed item is
        // history now, not part of the still-open tab this page works with
        $tableOrder->load(['items' => fn ($q) => $q->whereNull('sale_id')->orderBy('id'), 'table']);

        return Inertia::render('App/Restaurant/Order', [
            'order' => [
                'id' => $tableOrder->id,
                'status' => $tableOrder->status,
                'table_id' => $tableOrder->restaurant_table_id,
                'table_name' => $tableOrder->table?->name,
                'items' => $tableOrder->items,
                'total' => $tableOrder->total(),
                'has_unprinted' => $tableOrder->items->whereNull('kot_printed_at')->isNotEmpty(),
                'order_source' => $tableOrder->order_source,
                'delivery_platform' => $tableOrder->delivery_platform,
                'kitchen_note' => $tableOrder->kitchen_note,
                'waiter_name' => $tableOrder->waiter_name,
                'bill_count' => $tableOrder->sales()->count(),
            ],
            'products' => Product::with('category')->orderByDesc('id')->get(),
            'categories' => ProductCategory::orderBy('name')->get(),
            // for the "merge" picker — another occupied table whose order
            // can be folded into this one
            'otherOccupiedTables' => \App\Models\RestaurantTable::where('status', 'occupied')
                ->where('id', '!=', $tableOrder->restaurant_table_id)
                ->orderBy('name')->get(['id', 'name']),
            // for the "transfer" picker — a free table this order can move to
            'freeTables' => \App\Models\RestaurantTable::where('status', 'free')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function addItem(Request $request, TableOrder $tableOrder)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($tableOrder, $data) {
            $lockedOrder = TableOrder::whereKey($tableOrder->id)->lockForUpdate()->first();
            if ($lockedOrder->status !== 'open') {
                abort(422, 'এই অর্ডারটি আর খোলা নেই।');
            }

            $product = Product::withCount('variants')->whereKey($data['product_id'])->lockForUpdate()->first();

            if (! $product) {
                abort(422, 'পণ্যটি খুঁজে পাওয়া যায়নি।');
            }
            // table orders don't support the variant/pack-size picker (out
            // of scope for MVP) — same invariant-safety reasoning as
            // PosController: never silently sell "in general" off a product
            // whose stock is only meaningful as a sum of variants
            if ($product->variants_count > 0) {
                abort(422, "{$product->name} ভ্যারিয়েন্ট পণ্য — টেবিল অর্ডারে যোগ করা যাবে না।");
            }
            if ($product->stock < $data['qty']) {
                abort(422, "পর্যাপ্ত স্টক নেই: {$product->name} (আছে {$product->stock})।");
            }

            $product->decrement('stock', $data['qty']);
            // best-effort ingredient consumption for a recipe-linked dish — see IngredientConsumption
            \App\Support\IngredientConsumption::apply($product, (float) $data['qty']);

            // merge into the same product's not-yet-printed line instead of
            // adding a duplicate row — a KOT-printed line is left alone
            // (the kitchen already started on that batch; a repeat order
            // becomes its own new line, same as a second round at a
            // restaurant would be a separate ticket)
            $existing = TableOrderItem::where('table_order_id', $lockedOrder->id)
                ->where('product_id', $product->id)
                ->whereNull('kot_printed_at')
                ->first();

            if ($existing) {
                $existing->increment('qty', $data['qty']);
            } else {
                TableOrderItem::create([
                    'shop_id' => Tenancy::id(),
                    'table_order_id' => $lockedOrder->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'qty' => $data['qty'],
                    'price' => $product->price,
                    'cost' => $product->cost,
                ]);
            }
        });

        return back()->with('success', 'যোগ হয়েছে।');
    }

    /**
     * Order source (dine-in/takeaway/delivery + which 3rd-party platform)
     * and the free-text kitchen instruction note — pure metadata, no
     * stock/money involved, so a plain status guard is enough here (unlike
     * addItem/bill/cancel, there's no double-processing risk to lock against).
     */
    public function updateMeta(Request $request, TableOrder $tableOrder)
    {
        if ($tableOrder->status !== 'open') {
            abort(422, 'এই অর্ডারটি আর খোলা নেই।');
        }

        $data = $request->validate([
            'order_source' => ['required', 'in:dine_in,takeaway,delivery'],
            'delivery_platform' => ['nullable', 'string', 'max:100'],
            'kitchen_note' => ['nullable', 'string', 'max:1000'],
            'waiter_name' => ['nullable', 'string', 'max:100'],
        ]);

        $tableOrder->update([
            'order_source' => $data['order_source'],
            'delivery_platform' => $data['order_source'] === 'delivery' ? ($data['delivery_platform'] ?? null) : null,
            'kitchen_note' => $data['kitchen_note'] ?? null,
            'waiter_name' => $data['waiter_name'] ?? null,
        ]);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    /** One-tap "−" on the order screen's qty stepper — removes the whole line once qty hits 0. */
    public function decrementItem(TableOrderItem $tableOrderItem)
    {
        DB::transaction(function () use ($tableOrderItem) {
            $lockedOrder = TableOrder::whereKey($tableOrderItem->table_order_id)->lockForUpdate()->first();
            if ($lockedOrder->status !== 'open') {
                abort(422, 'এই অর্ডারটি আর খোলা নেই।');
            }

            if ($tableOrderItem->product_id) {
                Product::whereKey($tableOrderItem->product_id)->lockForUpdate()->increment('stock', 1);
            }

            if ($tableOrderItem->qty <= 1) {
                $tableOrderItem->delete();
            } else {
                $tableOrderItem->decrement('qty');
            }
        });

        return back()->with('success', 'কমানো হয়েছে।');
    }

    public function removeItem(TableOrderItem $tableOrderItem)
    {
        DB::transaction(function () use ($tableOrderItem) {
            $lockedOrder = TableOrder::whereKey($tableOrderItem->table_order_id)->lockForUpdate()->first();
            if ($lockedOrder->status !== 'open') {
                abort(422, 'এই অর্ডারটি আর খোলা নেই।');
            }

            if ($tableOrderItem->product_id) {
                Product::whereKey($tableOrderItem->product_id)->lockForUpdate()->increment('stock', $tableOrderItem->qty);
            }
            $tableOrderItem->delete();
        });

        return back()->with('success', 'বাদ দেওয়া হয়েছে।');
    }

    /** Toggles one line between served/not-served — separate from kot_printed_at, since a kitchen can be mid-prep on something already sent but not yet brought to the table. */
    public function toggleServed(TableOrderItem $tableOrderItem)
    {
        DB::transaction(function () use ($tableOrderItem) {
            // locked-then-rechecked like every other mutating action in this
            // controller — no stock/money is at stake here, but a plain
            // find()-then-check still leaves a window for a cancel/bill
            // happening between the check and the update
            $lockedOrder = TableOrder::whereKey($tableOrderItem->table_order_id)->lockForUpdate()->first();
            if (! $lockedOrder || $lockedOrder->status !== 'open') {
                abort(422, 'এই অর্ডারটি আর খোলা নেই।');
            }

            $tableOrderItem->update(['served_at' => $tableOrderItem->served_at ? null : now()]);
        });

        return back();
    }

    /** Marks every not-yet-sent (and not yet split-billed away) item as sent to the kitchen — the frontend triggers the actual print. */
    public function printKot(TableOrder $tableOrder)
    {
        if ($tableOrder->status !== 'open') {
            abort(422, 'এই অর্ডারটি আর খোলা নেই।');
        }

        TableOrderItem::where('table_order_id', $tableOrder->id)->whereNull('kot_printed_at')->whereNull('sale_id')->update(['kot_printed_at' => now()]);

        return back();
    }

    public function cancel(TableOrder $tableOrder)
    {
        DB::transaction(function () use ($tableOrder) {
            $lockedOrder = TableOrder::whereKey($tableOrder->id)->lockForUpdate()->first();
            if ($lockedOrder->status !== 'open') {
                abort(422, 'এই অর্ডারটি আর খোলা নেই।');
            }

            // only the still-unbilled remainder — if a split bill already
            // settled some items, those already became a real Sale and
            // must not have their stock given back a second time here
            $openItems = TableOrderItem::where('table_order_id', $lockedOrder->id)->whereNull('sale_id')->get();
            foreach ($openItems as $item) {
                if ($item->product_id) {
                    Product::whereKey($item->product_id)->lockForUpdate()->increment('stock', $item->qty);
                }
            }
            $lockedOrder->update(['status' => 'cancelled']);
            $lockedOrder->table?->update(['status' => 'free']);

            $label = $lockedOrder->table?->name ?? 'টেকঅ্যাওয়ে/পার্সেল';
            Activity::log('restaurant.cancel', "'{$label}'-এর অর্ডার বাতিল করা হয়েছে (স্টক ফেরত)।", $lockedOrder);
        });

        return redirect()->route('app.restaurant.tables.index')->with('success', 'অর্ডার বাতিল করা হয়েছে — স্টক ফেরত দেওয়া হয়েছে।');
    }

    /**
     * Settles the bill — same discount/multi-tender/customer-due math as
     * PosController::checkout, deliberately kept in lockstep with it, but
     * without decrementing stock again: every item here already took its
     * stock when it was added to the order (addItem), not now.
     *
     * Optionally accepts `item_ids` — a subset of this order's still-open
     * line ids — for a split bill: only those lines become this Sale, the
     * rest stay open on the table for a later bill (or a further split).
     * Omitting it bills everything still open, exactly as before. The order
     * only actually closes (status=billed, table freed) once nothing with a
     * null sale_id is left on it.
     */
    public function bill(Request $request, TableOrder $tableOrder)
    {
        $data = $request->validate([
            'discount' => ['nullable', 'numeric', 'min:0'],
            'complimentary' => ['nullable', 'boolean'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', 'in:cash,bkash,nagad'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);

        $shopId = Tenancy::id();

        $sale = DB::transaction(function () use ($tableOrder, $data, $shopId) {
            // locked and re-checked here, not just by the route firing once
            // — a double-tapped "bill" button must never create two Sales
            // from the same table order
            $lockedOrder = TableOrder::whereKey($tableOrder->id)->lockForUpdate()->first();
            if ($lockedOrder->status !== 'open') {
                abort(422, 'এই অর্ডারটি আর খোলা নেই।');
            }

            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();
            $itemsQuery = TableOrderItem::where('table_order_id', $lockedOrder->id)->whereNull('sale_id');
            if (! empty($data['item_ids'])) {
                // every named id must actually belong to this order and
                // still be open — silently ignoring a stale/foreign id
                // would let the billed total quietly not match what the
                // cashier thinks they selected
                $itemsQuery->whereIn('id', $data['item_ids']);
            }
            $items = $itemsQuery->get();

            if (! empty($data['item_ids']) && $items->count() !== count($data['item_ids'])) {
                abort(422, 'বাছাই করা কিছু আইটেম এই টেবিলের খোলা তালিকায় নেই — পেজ রিলোড করে আবার চেষ্টা করুন।');
            }

            if ($items->isEmpty()) {
                abort(422, 'এই টেবিলে কোনো আইটেম যোগ করা হয়নি।');
            }

            $subtotal = (float) $items->sum(fn (TableOrderItem $i) => $i->price * $i->qty);
            $profit = (float) $items->sum(fn (TableOrderItem $i) => ($i->price - $i->cost) * $i->qty);

            // free/staff-meal bill — see PosController::performCheckout's
            // matching comment; forcing discount = subtotal lands $total on
            // 0 and flows through every check below unchanged
            $isComplimentary = (bool) ($data['complimentary'] ?? false);
            $discount = $isComplimentary ? $subtotal : min((float) ($data['discount'] ?? 0), $subtotal);
            $total = $subtotal - $discount;
            $profit -= $discount;

            $vat = 0;
            if ($shop->vat_mode === 'full') {
                $vat = round($total * 15 / 115, 2);
            } elseif ($shop->vat_mode === 'turnover') {
                $vat = round($total * (float) $shop->turnover_rate / 100, 2);
            }

            // additive, unlike VAT above — a restaurant's service charge is
            // added on top of the bill, not backed out of an already-inclusive
            // price, so it must be folded into $total before payments/due below
            $serviceCharge = 0;
            if ($shop->service_charge_rate !== null) {
                $serviceCharge = round($total * (float) $shop->service_charge_rate / 100, 2);
                $total += $serviceCharge;
            }

            $customer = null;
            $phone = trim($data['customer_phone'] ?? '');
            $name = trim($data['customer_name'] ?? '');
            if ($phone !== '') {
                $customer = Customer::where('phone', $phone)->first();
                if (! $customer) {
                    $customer = Customer::create(['shop_id' => $shopId, 'name' => $name ?: 'Customer', 'phone' => $phone, 'due' => 0]);
                } elseif ($name !== '' && $customer->name !== $name) {
                    $customer->update(['name' => $name]);
                }
            }

            $payments = collect($data['payments'] ?? [])
                ->map(fn ($p) => ['method' => $p['method'], 'amount' => round((float) $p['amount'], 2)])
                ->values();
            $tendered = round((float) $payments->sum('amount'), 2);

            if ($tendered - $total > 0.01) {
                abort(422, 'পেমেন্টের পরিমাণ মোট বিলের চেয়ে বেশি হতে পারবে না।');
            }

            $dueRemainder = max(0, round($total - $tendered, 2));
            if ($dueRemainder > 0 && ! $customer) {
                abort(422, 'বাকি রাখতে হলে কাস্টমারের ফোন নম্বর দিন।');
            }

            $distinctMethods = $payments->pluck('method')->unique()->count();
            $paymentMode = match (true) {
                $total <= 0.01 => 'cash',
                $tendered <= 0.01 => 'credit',
                $dueRemainder > 0.01 || $distinctMethods > 1 => 'split',
                default => $payments->first()['method'],
            };

            $shop->invoice_counter += 1;
            $invoiceNo = 'INV-'.$shop->invoice_counter;

            $sale = Sale::create([
                'shop_id' => $shopId,
                'customer_id' => $customer?->id,
                'table_order_id' => $lockedOrder->id,
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'invoice_no' => $invoiceNo,
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'is_complimentary' => $isComplimentary,
                'service_charge' => $serviceCharge,
                'vat' => $vat,
                'total' => $total,
                'profit' => $profit,
                'payment_mode' => $paymentMode,
            ]);

            foreach ($items as $item) {
                SaleItem::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'unit_factor' => 1,
                    'qty' => $item->qty,
                    'price' => $item->price,
                    'discount' => 0,
                    'cost' => $item->cost,
                    // no batch_allocations — table orders don't participate
                    // in FEFO batch tracking (out of scope for this vertical)
                ]);
                // marks this specific line as billed — a split bill only
                // ever touches the lines it was given, everything else
                // stays open on the table
                $item->update(['sale_id' => $sale->id]);
            }

            foreach ($payments as $payment) {
                SalePayment::create([
                    'shop_id' => $shopId, 'sale_id' => $sale->id,
                    'method' => $payment['method'], 'amount' => $payment['amount'],
                ]);
                if ($payment['method'] === 'cash') {
                    $shop->cash_balance += $payment['amount'];
                } else {
                    $shop->bank_balance += $payment['amount'];
                }
            }

            if ($dueRemainder > 0) {
                $customer->increment('due', $dueRemainder);
            }
            $shop->save();

            if ($customer) {
                $customer->increment('total_spent', $total);
                $customer->increment('visits');
            }

            // only actually closes the table once every line has been
            // billed — a split bill that still leaves items open must
            // keep the table occupied and the order open for the rest
            $stillOpen = TableOrderItem::where('table_order_id', $lockedOrder->id)->whereNull('sale_id')->exists();
            $label = $lockedOrder->table?->name ?? 'টেকঅ্যাওয়ে/পার্সেল';
            if ($stillOpen) {
                Activity::log('restaurant.splitBill', "'{$label}'-এর একটি স্প্লিট বিল করা হয়েছে — মেমো {$invoiceNo}, বাকি আইটেম এখনো খোলা।", $sale, [
                    'total' => $total,
                ]);
            } else {
                $lockedOrder->update(['status' => 'billed', 'sale_id' => $sale->id]);
                $lockedOrder->table?->update(['status' => 'free']);

                Activity::log('restaurant.bill', "'{$label}' বিল করা হয়েছে — মেমো {$invoiceNo}।", $sale, [
                    'total' => $total,
                ]);
            }

            return $sale;
        });

        return redirect()->route('app.sales.show', $sale->id)->with('success', 'বিল সম্পন্ন হয়েছে।');
    }
}
