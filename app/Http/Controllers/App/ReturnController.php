<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\BatchStock;
use App\Support\SerialStock;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    public function index()
    {
        $shop = Shop::findOrFail(Tenancy::id());

        $products = Product::with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])
            ->get(['id', 'name', 'price', 'stock', 'barcode'])
            ->map(fn (Product $p) => $this->presentProductForPicker($p));

        return \Inertia\Inertia::render('App/Returns/Index', [
            'returnWindowDays' => $shop->return_window_days,
            'products' => $products,
        ]);
    }

    /**
     * Barcode lookup for the exchange "new item" picker's scanner (camera +
     * hardware) — same matching precedence as PosController::barcode()
     * (a variant's own barcode first, since it's more specific), but
     * returns the same cost-free shape as index()'s products prop. Never
     * reuse PosController::barcode() directly here — it returns the full
     * Product model including cost/margin, which this page deliberately
     * never ships to the browser (see presentProductForPicker).
     */
    public function barcodeLookup(string $barcode)
    {
        $variant = ProductVariant::with(['product.variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])->where('barcode', $barcode)->first();
        if ($variant) {
            return response()->json(['found' => true, 'product' => $this->presentProductForPicker($variant->product), 'variant_id' => $variant->id]);
        }

        $product = Product::with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])->where('barcode', $barcode)->first();
        if (! $product) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true, 'product' => $this->presentProductForPicker($product)]);
    }

    /**
     * Only what the exchange's "new item" picker needs — deliberately no
     * `cost`/`profit`, same reasoning as BarcodeLabelController: a cashier
     * granted `returns` but not `stock`/cost-visibility must never have
     * margin data shipped to their browser just because this page needs a
     * product list for picking a replacement item.
     */
    private function presentProductForPicker(Product $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'stock' => (float) $p->stock, 'barcode' => $p->barcode,
            'variants' => $p->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id, 'size' => $v->size, 'color' => $v->color, 'label' => $v->label(),
                'price' => $v->effectivePrice(), 'stock' => $v->stock, 'barcode' => $v->barcode,
            ]),
        ];
    }

    /**
     * Unchanged — the original no-receipt "lifetime cap" return flow for
     * shops that don't keep/ask for an invoice. process() below is the new,
     * separate invoice-linked return/exchange flow; this one stays exactly
     * as it was so nothing that already depends on it breaks.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'product_batch_id' => ['nullable', 'exists:product_batches,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'refund' => ['required', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        DB::transaction(function () use ($data) {
            $product = Product::whereKey($data['product_id'])->lockForUpdate()->firstOrFail();

            if ($product->variants()->exists()) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name} ভ্যারিয়েন্ট পণ্য — এখানে ফেরত নেওয়া যাবে না, স্টক পেজ থেকে নির্দিষ্ট ভ্যারিয়েন্টে স্টক ফেরত দিন।",
                ]);
            }

            if (! $product->sold_by_weight && floor($data['qty']) != $data['qty']) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।",
                ]);
            }

            $everSold = (float) SaleItem::where('product_id', $product->id)
                ->whereHas('sale')
                ->selectRaw('COALESCE(SUM(qty * unit_factor), 0) as total')
                ->value('total');
            $alreadyReturned = (float) SalesReturn::where('product_id', $product->id)->sum('qty');
            $returnable = $everSold - $alreadyReturned;

            if ($data['qty'] > $returnable) {
                throw ValidationException::withMessages([
                    'qty' => "এই পণ্যের সর্বোচ্চ {$returnable} ইউনিট ফেরত নেওয়া যাবে (এর বেশি কখনো বিক্রিই হয়নি)।",
                ]);
            }

            $batch = null;
            if (! empty($data['product_batch_id'])) {
                $batch = ProductBatch::whereKey($data['product_batch_id'])->lockForUpdate()->first();
                if (! $batch || $batch->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        'product_batch_id' => 'এই ব্যাচটি এই পণ্যের জন্য সঠিক নয়।',
                    ]);
                }
            }

            $product->increment('stock', $data['qty']);
            $batch?->increment('qty', $data['qty']);

            SalesReturn::create([
                'product_id' => $product->id,
                'product_batch_id' => $batch?->id,
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'qty' => $data['qty'],
                'refund' => $data['refund'],
                'phone' => $data['phone'] ?? null,
                'date' => now()->toDateString(),
                'type' => 'return',
            ]);

            Shop::whereKey(Tenancy::id())->decrement('cash_balance', $data['refund']);
        });

        return back()->with('success', 'Return recorded.');
    }

    /**
     * Finds the original bill(s) a counter return/exchange is against — by
     * invoice number (partial match), customer phone (exact), or a scanned
     * product/variant barcode. Tenant scoping on Sale/SaleItem already makes
     * this impossible to use to find another shop's invoice.
     *
     * `sale_id` is the direct-link variant — Sales/Show.vue's own "ফেরত /
     * এক্সচেঞ্জ" button sends the sale it's already looking at straight here
     * instead of making the cashier re-type/re-scan the invoice they're
     * already looking at. Still goes through the normal tenant scope, so it
     * 404s exactly like a text search would for another shop's sale.
     */
    public function lookup(Request $request)
    {
        $shop = Shop::findOrFail(Tenancy::id());

        if ($saleId = $request->get('sale_id')) {
            $sale = Sale::with(['customer', 'items'])->find($saleId);

            return response()->json(['sales' => $sale ? [$this->presentSale($sale, $shop)] : []]);
        }

        $q = trim((string) $request->get('q', ''));
        if ($q === '') {
            return response()->json(['sales' => []]);
        }

        $sales = Sale::query()
            ->where(function ($query) use ($q) {
                $query->where('invoice_no', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('phone', $q))
                    ->orWhereHas('items.product', fn ($p) => $p->where('barcode', $q))
                    ->orWhereHas('items.variant', fn ($v) => $v->where('barcode', $q));
            })
            ->with(['customer', 'items'])
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'sales' => $sales->map(fn (Sale $sale) => $this->presentSale($sale, $shop))->values(),
        ]);
    }

    private function presentSale(Sale $sale, Shop $shop): array
    {
        $ageDays = (int) \Carbon\Carbon::parse($sale->date)->diffInDays(now());
        $withinWindow = $shop->return_window_days === null || $ageDays <= $shop->return_window_days;

        return [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'date' => $sale->date->toDateString(),
            'time' => $sale->time,
            'total' => (float) $sale->total,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'payment_mode' => $sale->payment_mode,
            'customer' => $sale->customer ? ['id' => $sale->customer->id, 'name' => $sale->customer->name, 'phone' => $sale->customer->phone] : null,
            'within_window' => $withinWindow,
            'window_days' => $shop->return_window_days,
            'age_days' => $ageDays,
            'items' => $sale->items->map(function (SaleItem $item) {
                $returnedQty = (float) SalesReturn::where('sale_item_id', $item->id)->sum('qty');

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product_name,
                    'variant_label' => $item->variant_label,
                    'qty' => (float) $item->qty,
                    'price' => (float) $item->price,
                    'discount' => (float) $item->discount,
                    'returned_qty' => $returnedQty,
                    'returnable_qty' => max(0, (float) $item->qty - $returnedQty),
                ];
            })->values(),
        ];
    }

    /**
     * The invoice-linked return/exchange flow. One DB transaction, every
     * row involved locked, so a double-tap or a second tab can't process
     * the same return twice any more than checkout can double-sell —
     * re-validating returnable_qty happens only after every lock is held.
     */
    public function process(Request $request)
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer'],
            'action' => ['required', Rule::in(['refund', 'exchange'])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.condition' => ['required', Rule::in(['resalable', 'damaged'])],
            'override_window' => ['nullable', 'boolean'],
            'new_items' => ['nullable', 'array'],
            'new_items.*.product_id' => ['required_with:new_items', 'integer'],
            'new_items.*.product_variant_id' => ['nullable', 'integer'],
            'new_items.*.qty' => ['required_with:new_items', 'numeric', 'min:0.001'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', Rule::in(['cash', 'bkash', 'nagad'])],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $shopId = Tenancy::id();
            $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();
            $lockedShop = Shop::whereKey($shopId)->lockForUpdate()->firstOrFail();

            $sale = Sale::with('payments')->whereKey($data['sale_id'])->lockForUpdate()->first();
            if (! $sale) {
                abort(404, 'এই বিলটি পাওয়া যায়নি।');
            }

            $ageDays = (int) \Carbon\Carbon::parse($sale->date)->diffInDays(now());
            $withinWindow = $lockedShop->return_window_days === null || $ageDays <= $lockedShop->return_window_days;
            $user = Auth::guard('web')->user();
            $overrideRequested = (bool) ($data['override_window'] ?? false);
            if (! $withinWindow && ! ($user && $user->isOwner() && $overrideRequested)) {
                throw ValidationException::withMessages([
                    'sale_id' => "এই বিলের ফেরত নেওয়ার সময়সীমা ({$lockedShop->return_window_days} দিন) পার হয়ে গেছে। শুধু মালিক এটি override করতে পারবেন।",
                ]);
            }

            $customer = $sale->customer_id ? Customer::whereKey($sale->customer_id)->lockForUpdate()->first() : null;

            $saleItems = SaleItem::where('sale_id', $sale->id)
                ->whereIn('id', collect($data['lines'])->pluck('sale_item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalRefund = 0.0;
            $totalCost = 0.0;
            $returnRows = [];

            foreach ($data['lines'] as $line) {
                $item = $saleItems->get($line['sale_item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['lines' => 'এই বিলের একটি আইটেম খুঁজে পাওয়া যায়নি।']);
                }

                $returnQty = (float) $line['qty'];
                $alreadyReturned = (float) SalesReturn::where('sale_item_id', $item->id)->sum('qty');
                $returnable = (float) $item->qty - $alreadyReturned;

                if ($returnQty > $returnable + 0.0001) {
                    throw ValidationException::withMessages([
                        'lines' => "{$item->product_name}: সর্বোচ্চ {$returnable} ইউনিট ফেরত নেওয়া যাবে।",
                    ]);
                }

                [$refund, $cost] = $this->lineRefundAndCost($item, $sale, $returnQty);
                $totalRefund += $refund;
                $totalCost += $cost;

                $condition = $line['condition'];

                if ($condition === 'resalable') {
                    if ($item->product_variant_id) {
                        ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->increment('stock', $returnQty);
                        Product::whereKey($item->product_id)->lockForUpdate()->increment('stock', $returnQty);
                    } else {
                        Product::whereKey($item->product_id)->lockForUpdate()->increment('stock', $returnQty * (float) $item->unit_factor);

                        // Batch stock restores proportionally for a partial
                        // return too (see BatchStock::restorePartial) —
                        // $priorBatchRestored is whatever an earlier partial
                        // return of this same line already put back, so two
                        // partial returns of one line never double-restore.
                        $priorBatchRestored = (float) SalesReturn::where('sale_item_id', $item->id)->where('condition', 'resalable')->sum('qty');
                        BatchStock::restorePartial($item->batch_allocations, $priorBatchRestored, $returnQty);

                        // A serial-tracked item is always sold one at a time
                        // (qty=1) — there's no such thing as a genuinely
                        // partial return of it, so only flip it back to
                        // in_stock when the whole line comes back.
                        if (abs($returnQty - (float) $item->qty) < 0.0001) {
                            SerialStock::restore($item->product_serial_id);
                        }
                    }
                } else {
                    Damage::create([
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'qty' => $returnQty,
                        'reason' => 'কাস্টমার ফেরত — বিক্রিযোগ্য না',
                        'loss' => $cost,
                        'date' => now()->toDateString(),
                    ]);
                }

                $returnRows[] = [
                    'item' => $item,
                    'qty' => $returnQty,
                    'refund' => $refund,
                    'cost' => $cost,
                    'condition' => $condition,
                ];
            }

            // settle the refund: whatever portion of the ORIGINAL sale was
            // still on credit (never actually collected) reduces the
            // customer's due instead of being paid out as cash — the shop
            // never held that money to begin with.
            // Legacy pre-split-payment sale (or a pure-credit sale): no
            // SalePayment rows exist at all, so `payment_mode` alone is the
            // source of truth for how much was actually tendered — same
            // branching as SaleReversal::reverse().
            if ($sale->payments->isEmpty()) {
                $tendered = $sale->payment_mode === 'credit' ? 0.0 : (float) $sale->total;
            } else {
                $tendered = (float) $sale->payments->sum('amount');
            }
            $dueAtCheckout = max(0, (float) $sale->total - $tendered);
            $refundDuePortion = (float) $sale->total > 0 ? round($totalRefund * $dueAtCheckout / (float) $sale->total, 2) : 0.0;
            $refundCashPortion = round($totalRefund - $refundDuePortion, 2);

            $pointsDeducted = null;
            if ($customer && $sale->points_earned && (float) $sale->total > 0) {
                $pointsDeducted = (int) max(0, min($customer->loyalty_points, round($sale->points_earned * ($totalRefund / (float) $sale->total))));
            }

            $isExchange = $data['action'] === 'exchange' && ! empty($data['new_items']);
            $exchangeSale = null;
            $dueApplied = 0.0;

            if ($isExchange) {
                $exchangeSale = $this->createExchangeSale($data, $shopId, $userId, $lockedShop, $customer, $totalRefund, $sale);
                $exchangeSale->load('items'); // the memo needs to list what was given (see Returns/Index.vue), same as Sales/Show.vue already loads items for a sale's own detail page
            } else {
                // refundDuePortion is the share of THIS refund attributable to
                // money the shop never actually collected at checkout time —
                // but by the time of the return, the customer may have since
                // paid some or all of that due down already. Only reduce due
                // by what's actually still outstanding; whatever refund
                // portion can't be absorbed there (because due has already
                // been settled) must still go out as cash, or that money
                // simply vanishes from both books.
                if ($refundDuePortion > 0 && $customer) {
                    $dueApplied = min($refundDuePortion, (float) $customer->due);
                    $customer->due = (float) $customer->due - $dueApplied;
                }
                $cashOut = round($refundCashPortion + ($refundDuePortion - $dueApplied), 2);
                if ($cashOut != 0) {
                    // deliberately allowed to go negative, same reasoning as
                    // the original store() flow — a refund bigger than
                    // cash-on-hand is a real shortfall that must stay visible
                    $lockedShop->cash_balance = (float) $lockedShop->cash_balance - $cashOut;
                }
            }

            if ($pointsDeducted) {
                $customer->decrement('loyalty_points', $pointsDeducted);
            }
            if ($customer) {
                $customer->save();
            }
            $lockedShop->save();

            $createdReturns = [];
            foreach ($returnRows as $row) {
                $createdReturns[] = SalesReturn::create([
                    'product_id' => $row['item']->product_id,
                    'product_variant_id' => $row['item']->product_variant_id,
                    'sale_id' => $sale->id,
                    'sale_item_id' => $row['item']->id,
                    'customer_id' => $customer?->id,
                    'exchange_sale_id' => $exchangeSale?->id,
                    'user_id' => $userId,
                    'qty' => $row['qty'],
                    'refund' => $row['refund'],
                    'cost' => $row['cost'],
                    'condition' => $row['condition'],
                    'type' => $isExchange ? 'exchange' : 'return',
                    'applied_to_due' => $dueApplied > 0,
                    'loyalty_points_deducted' => $pointsDeducted,
                    'phone' => $customer?->phone,
                    'date' => now()->toDateString(),
                ]);
            }

            Activity::log(
                $isExchange ? 'return.exchange' : 'return.process',
                "মেমো '{$sale->invoice_no}' থেকে ".count($returnRows)." আইটেম ".($isExchange ? 'এক্সচেঞ্জ' : 'ফেরত')." করা হয়েছে, মোট ৳".round($totalRefund, 2),
                $sale,
                ['refund' => round($totalRefund, 2), 'cost' => round($totalCost, 2)]
            );

            $sale->setRelation('customer', $customer); // avoids a second query — already locked/fetched above; the memo/WhatsApp button needs the phone number

            return [
                'sale' => $sale,
                'returns' => $createdReturns,
                'exchange_sale' => $exchangeSale,
                'total_refund' => round($totalRefund, 2),
                // what actually happened to the money, not the original
                // (pre-shortfall-correction) estimate — see the due/cash
                // split above. An exchange settles entirely through its own
                // new sale's due/cash handling instead, so these are 0 there.
                'refund_due_portion' => $isExchange ? 0.0 : round($dueApplied, 2),
                'refund_cash_portion' => $isExchange ? 0.0 : round($cashOut, 2),
                'points_deducted' => $pointsDeducted,
            ];
        });

        return response()->json($result);
    }

    /** Proportional refund: the per-unit price the customer actually paid for this line, after BOTH its own line discount and its share of the whole bill's overall discount — never today's price, never more than was actually paid. */
    private function lineRefundAndCost(SaleItem $item, Sale $sale, float $returnQty): array
    {
        $lineTotal = (float) $item->price * (float) $item->qty - (float) $item->discount;
        $subtotal = (float) $sale->subtotal;
        $allocatedOverallDiscount = $subtotal > 0 ? (float) $sale->discount * ($lineTotal / $subtotal) : 0.0;
        $netPaidForLine = max(0, $lineTotal - $allocatedOverallDiscount);
        $perUnitNetPaid = (float) $item->qty > 0 ? $netPaidForLine / (float) $item->qty : 0.0;

        $refund = round($perUnitNetPaid * $returnQty, 2);
        $cost = round((float) $item->cost * $returnQty, 2);

        return [$refund, $cost];
    }

    /**
     * The exchange's replacement item(s) become their own Sale, sale_type
     * 'exchange' — kept deliberately separate from PosController::
     * performCheckout (no BOGO/coupon/wholesale, no quotation, nothing that
     * could regress the real POS checkout path) since an exchange is always
     * a simple like-for-like swap at today's price, settled against the
     * return's own credit rather than a normal tender set.
     */
    private function createExchangeSale(array $data, int $shopId, ?int $userId, Shop $lockedShop, ?Customer $customer, float $credit, Sale $originalSale): Sale
    {
        $productIds = collect($data['new_items'])->pluck('product_id')->unique();
        $products = Product::whereIn('id', $productIds)->withCount('variants')->lockForUpdate()->get()->keyBy('id');

        $variantIds = collect($data['new_items'])->pluck('product_variant_id')->filter()->unique();
        $variants = $variantIds->isEmpty() ? collect() : ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

        $newTotal = 0.0;
        $newCost = 0.0;
        $lines = [];

        foreach ($data['new_items'] as $row) {
            $product = $products->get($row['product_id']);
            if (! $product) {
                abort(422, 'নতুন আইটেমের পণ্য খুঁজে পাওয়া যায়নি।');
            }

            $variant = null;
            $price = (float) $product->price;
            $cost = (float) $product->cost;
            $variantLabel = null;
            $qty = (float) $row['qty'];

            if (! empty($row['product_variant_id'])) {
                $variant = $variants->get($row['product_variant_id']);
                if (! $variant || $variant->product_id !== $product->id) {
                    abort(422, "এই ভ্যারিয়েন্টটি {$product->name}-এর জন্য সঠিক নয়।");
                }
                $price = $variant->effectivePrice();
                $cost = $variant->effectiveCost();
                $variantLabel = $variant->label();
                if ($variant->stock < $qty) {
                    abort(422, "পর্যাপ্ত স্টক নেই: {$product->name} ({$variantLabel})।");
                }
                $variant->decrement('stock', $qty);
                $product->decrement('stock', $qty);
            } else {
                if ($product->variants_count > 0) {
                    abort(422, "{$product->name}-এর জন্য একটা সাইজ/রং বাছাই করুন।");
                }
                if ($product->stock < $qty) {
                    abort(422, "পর্যাপ্ত স্টক নেই: {$product->name}।");
                }
                $product->decrement('stock', $qty);
            }

            $lineTotal = $price * $qty;
            $newTotal += $lineTotal;
            $newCost += $cost * $qty;

            $lines[] = [
                'product' => $product, 'variant' => $variant, 'variant_label' => $variantLabel,
                'qty' => $qty, 'price' => $price, 'cost' => $cost,
            ];
        }

        $lockedShop->invoice_counter += 1;
        $invoiceNo = 'INV-'.$lockedShop->invoice_counter;

        // net_settlement > 0: customer must pay this much more; < 0: shop owes the customer this much back
        $netSettlement = round($newTotal - $credit, 2);

        $tenderedExtra = 0.0;
        if ($netSettlement > 0) {
            $payments = collect($data['payments'] ?? []);
            $tenderedExtra = round((float) $payments->sum('amount'), 2);
            if ($tenderedExtra - $netSettlement > 0.01) {
                abort(422, 'পেমেন্টের পরিমাণ বাকি থাকা টাকার চেয়ে বেশি হতে পারবে না।');
            }
            $dueRemainder = round($netSettlement - $tenderedExtra, 2);
            if ($dueRemainder > 0.01 && ! $customer) {
                abort(422, 'বাকি রাখতে হলে কাস্টমারের ফোন নম্বর দিন।');
            }
            foreach ($payments as $payment) {
                if ($payment['method'] === 'cash') {
                    $lockedShop->cash_balance = (float) $lockedShop->cash_balance + (float) $payment['amount'];
                } else {
                    $lockedShop->bank_balance = (float) $lockedShop->bank_balance + (float) $payment['amount'];
                }
            }
            if ($dueRemainder > 0.01 && $customer) {
                $customer->due = (float) $customer->due + $dueRemainder;
            }
            $paymentMode = $dueRemainder > 0.01 ? ($tenderedExtra > 0 ? 'split' : 'credit') : ($payments->count() > 1 ? 'split' : ($payments->first()['method'] ?? 'cash'));
        } elseif ($netSettlement < 0) {
            // shop owes the customer back — reduces their existing due first (if any), the rest paid out of cash, same preference as a plain refund
            $owed = abs($netSettlement);
            if ($customer && (float) $customer->due > 0) {
                $applied = min($owed, (float) $customer->due);
                $customer->due = (float) $customer->due - $applied;
                $owed -= $applied;
            }
            if ($owed > 0) {
                $lockedShop->cash_balance = (float) $lockedShop->cash_balance - $owed;
            }
            $paymentMode = 'cash';
        } else {
            $paymentMode = 'cash';
        }

        $exchangeSale = Sale::create([
            'shop_id' => $shopId,
            'customer_id' => $customer?->id,
            'user_id' => $userId,
            'invoice_no' => $invoiceNo,
            'date' => now()->toDateString(),
            'time' => now()->toTimeString(),
            'subtotal' => $newTotal,
            'discount' => 0,
            'is_complimentary' => false,
            'service_charge' => 0,
            'vat' => 0,
            'total' => $newTotal,
            'profit' => $newTotal - $newCost,
            'payment_mode' => $paymentMode,
            'sale_type' => 'exchange',
        ]);

        foreach ($lines as $line) {
            SaleItem::create([
                'shop_id' => $shopId,
                'sale_id' => $exchangeSale->id,
                'product_id' => $line['product']->id,
                'product_variant_id' => $line['variant']?->id,
                'product_name' => $line['product']->name,
                'variant_label' => $line['variant_label'],
                'unit_factor' => 1,
                'qty' => $line['qty'],
                'price' => $line['price'],
                'discount' => 0,
                'cost' => $line['cost'],
            ]);
        }

        foreach (($data['payments'] ?? []) as $payment) {
            SalePayment::create([
                'shop_id' => $shopId,
                'sale_id' => $exchangeSale->id,
                'method' => $payment['method'],
                'amount' => $payment['amount'],
            ]);
        }

        // Not a DB column — set on the model purely so the memo can show the
        // price difference and what the customer actually paid/received,
        // without the frontend having to re-derive it from new_items_total
        // minus credit (which it can't do authoritatively anyway).
        $exchangeSale->net_settlement = $netSettlement;

        // An exchange isn't a new customer visit, and shouldn't earn fresh
        // loyalty points (see the points-deduction on the return side above)
        // — only money that genuinely changed hands beyond the swap itself
        // (a price top-up) counts toward total_spent.
        if ($customer && $netSettlement > 0) {
            $customer->total_spent = (float) $customer->total_spent + min($netSettlement, $tenderedExtra + max(0, $netSettlement - $tenderedExtra));
        }

        return $exchangeSale;
    }
}
