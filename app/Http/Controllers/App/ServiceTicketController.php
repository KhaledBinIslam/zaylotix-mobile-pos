<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketItem;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A repair/service job, start to finish — intake (device + customer +
 * problem), parts/labor added as the job progresses (a real inventory part
 * takes its stock the moment it's added, same timing as
 * TableOrderController::addItem — never deferred to billing), then bill()
 * turns the whole thing into a completely ordinary Sale (sale_type=
 * 'service'), so it's voidable/reportable with zero special-casing anywhere
 * else in the app. Mirrors TableOrderController's open-tab-then-bill shape
 * throughout, adapted for "which device, whose it is, who's fixing it"
 * instead of "which table, which waiter".
 */
class ServiceTicketController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status');

        $tickets = ServiceTicket::with(['customer:id,name,phone', 'items'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('App/ServiceTickets/Index', [
            'tickets' => $tickets,
            'status' => $status,
            'products' => Product::withCount('variants')->orderBy('name')->get(['id', 'name', 'emoji', 'barcode', 'stock', 'cost', 'price']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'device_label' => ['required', 'string', 'max:255'],
            'product_id' => ['nullable', 'exists:products,id'],
            'imei' => ['nullable', 'string', 'max:50'],
            'problem_description' => ['nullable', 'string'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'is_warranty_claim' => ['nullable', 'boolean'],
            'promised_at' => ['nullable', 'date'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        $ticket = DB::transaction(function () use ($data) {
            $shopId = Tenancy::id();

            $customer = null;
            $phone = trim($data['customer_phone'] ?? '');
            if ($phone !== '') {
                $customer = Customer::where('phone', $phone)->first();
                if (! $customer) {
                    $customer = Customer::create(['shop_id' => $shopId, 'name' => trim($data['customer_name'] ?? '') ?: 'Customer', 'phone' => $phone, 'due' => 0]);
                }
            }

            // a serial typed/scanned at intake only needs to exist — it's
            // never required to be 'in_stock' (the device is already out
            // in the world, being brought in for repair, not sold again)
            $serial = null;
            $imei = trim($data['imei'] ?? '');
            if ($imei !== '') {
                $serial = ProductSerial::where('imei', $imei)->first();
            }

            return ServiceTicket::create([
                'shop_id' => $shopId,
                'customer_id' => $customer?->id,
                'product_id' => $data['product_id'] ?? $serial?->product_id,
                'product_serial_id' => $serial?->id,
                'device_label' => $data['device_label'],
                'problem_description' => $data['problem_description'] ?? null,
                'technician_name' => $data['technician_name'] ?? null,
                'status' => 'received',
                'is_warranty_claim' => $data['is_warranty_claim'] ?? false,
                'received_at' => now(),
                'promised_at' => $data['promised_at'] ?? null,
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
            ]);
        });

        Activity::log('serviceTicket.create', "নতুন সার্ভিস টিকেট #{$ticket->id} — '{$ticket->device_label}'।", $ticket);

        return redirect()->route('app.serviceTickets.show', $ticket)->with('success', 'সার্ভিস টিকেট তৈরি হয়েছে।');
    }

    public function show(ServiceTicket $ticket)
    {
        $ticket->load(['items' => fn ($q) => $q->whereNull('sale_id')->orderBy('id'), 'customer', 'product:id,name,emoji', 'serial:id,imei,warranty_expiry', 'sale:id,invoice_no,date,total']);

        // device/customer history — every OTHER ticket for the same specific
        // unit (if serial-tracked) or the same customer, so a technician can
        // see "has this come back before" without leaving the page. Neither
        // id set (a walk-in device with no phone number given) means
        // nothing to match on, not "show everything" — history stays empty.
        $history = collect();
        if ($ticket->product_serial_id || $ticket->customer_id) {
            $history = ServiceTicket::where('id', '!=', $ticket->id)
                ->where(fn ($q) => $q
                    ->when($ticket->product_serial_id, fn ($q2) => $q2->orWhere('product_serial_id', $ticket->product_serial_id))
                    ->when($ticket->customer_id, fn ($q2) => $q2->orWhere('customer_id', $ticket->customer_id)))
                ->with('customer:id,name')
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'device_label', 'status', 'received_at', 'customer_id']);
        }

        return Inertia::render('App/ServiceTickets/Show', [
            'ticket' => $ticket,
            'total' => $ticket->total(),
            'history' => $history,
            'products' => Product::withCount('variants')->orderBy('name')->get(['id', 'name', 'emoji', 'barcode', 'stock', 'cost', 'price']),
        ]);
    }

    /** A real inventory part (product_id, stock taken now) or a free-typed line — labor, or a part not carried in stock. */
    public function addItem(Request $request, ServiceTicket $ticket)
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'is_labor' => ['nullable', 'boolean'],
            'label' => ['required_without:product_id', 'nullable', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($ticket, $data) {
            $lockedTicket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->first();
            if (in_array($lockedTicket->status, ['delivered', 'cancelled'], true)) {
                abort(422, 'এই টিকেটটি আর খোলা নেই।');
            }

            $label = $data['label'] ?? null;
            $cost = $data['cost'] ?? null;

            if (! empty($data['product_id'])) {
                $lockedProduct = Product::withCount('variants')->whereKey($data['product_id'])->lockForUpdate()->first();
                if (! $lockedProduct) {
                    abort(422, 'পণ্যটি খুঁজে পাওয়া যায়নি।');
                }
                if ($lockedProduct->variants_count > 0) {
                    abort(422, "{$lockedProduct->name} ভ্যারিয়েন্ট পণ্য — সার্ভিস টিকেটে যোগ করা যাবে না।");
                }
                if ($lockedProduct->isStockTracked()) {
                    if ($lockedProduct->stock < $data['qty']) {
                        abort(422, "পর্যাপ্ত স্টক নেই: {$lockedProduct->name} (আছে {$lockedProduct->stock})।");
                    }
                    $lockedProduct->decrement('stock', $data['qty']);
                }
                $label = $label ?: $lockedProduct->name;
                $cost = $cost ?? (float) $lockedProduct->cost;
            }

            ServiceTicketItem::create([
                'shop_id' => Tenancy::id(),
                'service_ticket_id' => $lockedTicket->id,
                'product_id' => $data['product_id'] ?? null,
                'is_labor' => $data['is_labor'] ?? false,
                'label' => $label,
                'qty' => $data['qty'],
                'cost' => $cost,
                'price' => $data['price'],
            ]);
        });

        return back()->with('success', 'যোগ হয়েছে।');
    }

    public function removeItem(ServiceTicketItem $item)
    {
        DB::transaction(function () use ($item) {
            $lockedTicket = ServiceTicket::whereKey($item->service_ticket_id)->lockForUpdate()->first();
            if (in_array($lockedTicket->status, ['delivered', 'cancelled'], true)) {
                abort(422, 'এই টিকেটটি আর খোলা নেই।');
            }
            if ($item->sale_id) {
                abort(422, 'এই লাইনটি ইতিমধ্যে বিল করা হয়ে গেছে।');
            }

            if ($item->product_id) {
                $lockedProduct = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if ($lockedProduct?->isStockTracked()) {
                    $lockedProduct->increment('stock', $item->qty);
                }
            }
            $item->delete();
        });

        return back()->with('success', 'বাদ দেওয়া হয়েছে।');
    }

    /** Status/technician/promise-date changes — pure metadata+workflow, no stock/money involved. */
    public function updateStatus(Request $request, ServiceTicket $ticket)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:received,in_progress,waiting_parts,ready'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'promised_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (in_array($ticket->status, ['delivered', 'cancelled'], true)) {
            abort(422, 'এই টিকেটটি আর খোলা নেই।');
        }

        $ticket->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'হালনাগাদ হয়েছে।');
    }

    /**
     * Settles the bill — same discount-free, multi-tender, customer-due math
     * as TableOrderController::bill(), minus the discount/VAT/service-charge
     * layer (a repair bill is a flat total of its own lines, no restaurant
     * tax rules apply here) — without decrementing stock again: a parts line
     * already took its stock when it was added, not now.
     */
    public function bill(Request $request, ServiceTicket $ticket)
    {
        $data = $request->validate([
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', 'in:cash,bkash,nagad'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
        ]);

        $shopId = Tenancy::id();

        $sale = DB::transaction(function () use ($ticket, $data, $shopId) {
            $lockedTicket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->first();
            if (in_array($lockedTicket->status, ['delivered', 'cancelled'], true)) {
                abort(422, 'এই টিকেটটি আর খোলা নেই।');
            }

            $items = ServiceTicketItem::where('service_ticket_id', $lockedTicket->id)->whereNull('sale_id')->get();
            if ($items->isEmpty()) {
                abort(422, 'এই টিকেটে কোনো আইটেম যোগ করা হয়নি।');
            }

            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            $subtotal = (float) $items->sum(fn (ServiceTicketItem $i) => (float) $i->price * (float) $i->qty);
            $profit = (float) $items->sum(fn (ServiceTicketItem $i) => ((float) $i->price - (float) ($i->cost ?? 0)) * (float) $i->qty);

            $payments = collect($data['payments'] ?? [])
                ->map(fn ($p) => ['method' => $p['method'], 'amount' => round((float) $p['amount'], 2)])
                ->values();
            $tendered = round((float) $payments->sum('amount'), 2);

            if ($tendered - $subtotal > 0.01) {
                abort(422, 'পেমেন্টের পরিমাণ মোট বিলের চেয়ে বেশি হতে পারবে না।');
            }

            $dueRemainder = max(0, round($subtotal - $tendered, 2));
            if ($dueRemainder > 0 && ! $lockedTicket->customer_id) {
                abort(422, 'বাকি রাখতে হলে কাস্টমারের তথ্য দিন।');
            }

            $distinctMethods = $payments->pluck('method')->unique()->count();
            $paymentMode = match (true) {
                $subtotal <= 0.01 => 'cash',
                $tendered <= 0.01 => 'credit',
                $dueRemainder > 0.01 || $distinctMethods > 1 => 'split',
                default => $payments->first()['method'],
            };

            $shop->invoice_counter += 1;
            $invoiceNo = 'INV-'.$shop->invoice_counter;

            $sale = Sale::create([
                'shop_id' => $shopId,
                'customer_id' => $lockedTicket->customer_id,
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'invoice_no' => $invoiceNo,
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'subtotal' => $subtotal,
                'discount' => 0,
                'total' => $subtotal,
                'profit' => $profit,
                'payment_mode' => $paymentMode,
                'sale_type' => 'service',
            ]);

            foreach ($items as $item) {
                SaleItem::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->label,
                    'unit_factor' => 1,
                    'qty' => $item->qty,
                    'price' => $item->price,
                    'discount' => 0,
                    'cost' => $item->cost ?? 0,
                ]);
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

            $customer = $lockedTicket->customer_id ? Customer::find($lockedTicket->customer_id) : null;
            if ($dueRemainder > 0 && $customer) {
                $customer->increment('due', $dueRemainder);
            }
            $shop->save();

            if ($customer) {
                $customer->increment('total_spent', $subtotal);
                $customer->increment('visits');
            }

            $lockedTicket->update(['status' => 'delivered', 'delivered_at' => now(), 'sale_id' => $sale->id]);

            Activity::log('serviceTicket.bill', "সার্ভিস টিকেট #{$lockedTicket->id} বিল করা হয়েছে — মেমো {$invoiceNo}।", $sale, ['total' => $subtotal]);

            return $sale;
        });

        return redirect()->route('app.sales.show', $sale)->with('success', 'বিল করা হয়েছে।');
    }

    public function cancel(ServiceTicket $ticket)
    {
        DB::transaction(function () use ($ticket) {
            $lockedTicket = ServiceTicket::whereKey($ticket->id)->lockForUpdate()->first();
            if (in_array($lockedTicket->status, ['delivered', 'cancelled'], true)) {
                abort(422, 'এই টিকেটটি আর খোলা নেই।');
            }

            $openItems = ServiceTicketItem::where('service_ticket_id', $lockedTicket->id)->whereNull('sale_id')->get();
            foreach ($openItems as $item) {
                if ($item->product_id) {
                    $lockedProduct = Product::whereKey($item->product_id)->lockForUpdate()->first();
                    if ($lockedProduct?->isStockTracked()) {
                        $lockedProduct->increment('stock', $item->qty);
                    }
                }
            }

            $lockedTicket->update(['status' => 'cancelled']);

            Activity::log('serviceTicket.cancel', "সার্ভিস টিকেট #{$lockedTicket->id} বাতিল করা হয়েছে (স্টক ফেরত)।", $lockedTicket);
        });

        return redirect()->route('app.serviceTickets.index')->with('success', 'টিকেট বাতিল করা হয়েছে — স্টক ফেরত দেওয়া হয়েছে।');
    }
}
