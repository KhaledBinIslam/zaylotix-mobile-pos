<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\OnlineOrder;
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
            'product_variant_id' => ['nullable', 'integer'],
            'product_batch_id' => ['nullable', 'exists:product_batches,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'refund' => ['required', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        DB::transaction(function () use ($data) {
            $product = Product::withCount('variants')->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();

            if ($product->variants_count > 0 && empty($data['product_variant_id'])) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name}-এর সাইজ/রং আছে — একটা নির্দিষ্ট ভ্যারিয়েন্ট বেছে ফেরত নিন।",
                ]);
            }
            if ($product->variants_count === 0 && ! empty($data['product_variant_id'])) {
                throw ValidationException::withMessages([
                    'product_variant_id' => "{$product->name}-এর কোনো ভ্যারিয়েন্ট নেই।",
                ]);
            }

            if (! $product->sold_by_weight && floor($data['qty']) != $data['qty']) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।",
                ]);
            }

            $variant = null;
            if (! empty($data['product_variant_id'])) {
                $variant = ProductVariant::whereKey($data['product_variant_id'])->lockForUpdate()->first();
                if (! $variant || $variant->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        'product_variant_id' => 'এই ভ্যারিয়েন্টটি সঠিক নয়।',
                    ]);
                }
            }

            $everSold = (float) SaleItem::where('product_id', $product->id)
                ->when($variant, fn ($q) => $q->where('product_variant_id', $variant->id), fn ($q) => $q->whereNull('product_variant_id'))
                ->whereHas('sale')
                ->selectRaw('COALESCE(SUM(qty * unit_factor), 0) as total')
                ->value('total');
            $alreadyReturned = (float) SalesReturn::where('product_id', $product->id)
                ->when($variant, fn ($q) => $q->where('product_variant_id', $variant->id), fn ($q) => $q->whereNull('product_variant_id'))
                ->sum('qty');
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

            $variant?->applyStockDelta($data['qty']);
            $product->applyStockDelta($data['qty']);
            $batch?->increment('qty', $data['qty']);

            SalesReturn::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
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
     * product/variant barcode. Searches every shop sharing this one's
     * business root (itself, for a shop with no branches — identical to the
     * old plain tenant scope there), not just the exact active shop_id — a
     * branch can take back a sale that happened at a sibling branch (Khaled's
     * explicit ask: "branch এর বিল অন্য branch এ ফেরত/এক্সচেঞ্জ"). An
     * unrelated shop outside this business is still completely invisible —
     * businessShopIds() never reaches past this one's own root.
     *
     * `sale_id` is the direct-link variant — Sales/Show.vue's own "ফেরত /
     * এক্সচেঞ্জ" button sends the sale it's already looking at straight here
     * instead of making the cashier re-type/re-scan the invoice they're
     * already looking at.
     */
    public function lookup(Request $request)
    {
        $shop = Shop::findOrFail(Tenancy::id());
        $businessShopIds = Tenancy::businessShopIds($shop->id);

        // Eager-loading 'items' also applies SaleItem's own TenantScope by
        // default — for a cross-branch sale that would silently come back
        // empty (the items genuinely belong to the OTHER branch, not the
        // currently-active one), so it's unscoped explicitly here too.
        if ($saleId = $request->get('sale_id')) {
            $sale = Sale::withoutGlobalScopes()->whereIn('shop_id', $businessShopIds)
                ->with(['customer', 'items' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($saleId);

            return response()->json(['sales' => $sale ? [$this->presentSale($sale, $shop)] : []]);
        }

        $q = trim((string) $request->get('q', ''));
        if ($q === '') {
            return response()->json(['sales' => []]);
        }

        $sales = Sale::withoutGlobalScopes()
            ->whereIn('shop_id', $businessShopIds)
            ->where(function ($query) use ($q) {
                // dot-notation whereHas('items.product', ...) only drops
                // scopes on the FINAL relation in the chain — the
                // intermediate `items` (SaleItem) hop still carries its own
                // TenantScope by default, which would silently restrict a
                // cross-branch barcode scan back down to just the receiving
                // shop's own items. Chained manually instead so every hop is
                // explicitly unscoped.
                $query->where('invoice_no', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('phone', $q))
                    ->orWhereHas('items', fn ($i) => $i->withoutGlobalScopes()->whereHas('product', fn ($p) => $p->withoutGlobalScopes()->where('barcode', $q)))
                    ->orWhereHas('items', fn ($i) => $i->withoutGlobalScopes()->whereHas('variant', fn ($v) => $v->withoutGlobalScopes()->where('barcode', $q)));
            })
            ->with(['customer', 'items' => fn ($q) => $q->withoutGlobalScopes()])
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
        $isCrossBranch = $sale->shop_id !== $shop->id;

        return [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'date' => $sale->date->toDateString(),
            'time' => $sale->time,
            'total' => (float) $sale->total,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'payment_mode' => $sale->payment_mode,
            // so the cashier can see at a glance this bill was sold at a
            // sibling branch, not this one, before processing the return
            'origin_shop_name' => $isCrossBranch ? Shop::withoutGlobalScopes()->find($sale->shop_id)?->name : null,
            'customer' => $sale->customer ? ['id' => $sale->customer->id, 'name' => $sale->customer->name, 'phone' => $sale->customer->phone] : null,
            'within_window' => $withinWindow,
            'window_days' => $shop->return_window_days,
            'age_days' => $ageDays,
            'items' => $sale->items->map(function (SaleItem $item) {
                // business-wide, not shop-scoped — a prior return against
                // this sale could have been processed at ANY branch (this
                // one, or a sibling), and all of it counts toward how much
                // of this line is still returnable
                $returnedQty = (float) SalesReturn::withoutGlobalScopes()->where('sale_item_id', $item->id)->sum('qty');

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
            // 'lost' — see docs/FIX_PLAN.md's Phase 34 design note. Same
            // accounting treatment as 'damaged' (full write-off via the
            // existing Damage model), differing only in why.
            'lines.*.condition' => ['required', Rule::in(['resalable', 'damaged', 'lost'])],
            // free-text, one per return event — "editable return reasons"
            'reason' => ['nullable', 'string', 'max:255'],
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
            // $shopId is the RECEIVING branch — whichever one is physically
            // processing this return/exchange right now. $sale may belong
            // to a SIBLING branch entirely (Khaled's explicit ask: a branch
            // can take back a sale made elsewhere) — every money/stock
            // movement below settles against the RECEIVING branch
            // ($lockedShop), never the sale's own origin shop.
            $shopId = Tenancy::id();
            $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();
            $lockedShop = Shop::whereKey($shopId)->lockForUpdate()->firstOrFail();
            $businessShopIds = Tenancy::businessShopIds($shopId);

            $sale = Sale::withoutGlobalScopes()->whereIn('shop_id', $businessShopIds)
                ->with('payments')->whereKey($data['sale_id'])->lockForUpdate()->first();
            if (! $sale) {
                abort(404, 'এই বিলটি পাওয়া যায়নি।');
            }
            $isCrossBranch = $sale->shop_id !== $shopId;

            $ageDays = (int) \Carbon\Carbon::parse($sale->date)->diffInDays(now());
            $withinWindow = $lockedShop->return_window_days === null || $ageDays <= $lockedShop->return_window_days;
            $user = Auth::guard('web')->user();
            $overrideRequested = (bool) ($data['override_window'] ?? false);
            if (! $withinWindow && ! ($user && $user->isOwner() && $overrideRequested)) {
                throw ValidationException::withMessages([
                    'sale_id' => "এই বিলের ফেরত নেওয়ার সময়সীমা ({$lockedShop->return_window_days} দিন) পার হয়ে গেছে। শুধু মালিক এটি override করতে পারবেন।",
                ]);
            }

            // Customer is already business-wide by design (see Customer::class
            // / BusinessScope) — works unchanged whether this sale's customer
            // was first registered at this branch or a sibling one.
            $customer = $sale->customer_id ? Customer::whereKey($sale->customer_id)->lockForUpdate()->first() : null;

            $saleItems = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)
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
                // business-wide, not shop-scoped — a prior return against
                // this line could have already been processed at a sibling
                // branch, and all of it counts against what's still returnable
                $alreadyReturned = (float) SalesReturn::withoutGlobalScopes()->where('sale_item_id', $item->id)->sum('qty');
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

                // Batch/IMEI-serial bookkeeping belongs to whichever shop
                // actually holds those rows — the sale's ORIGIN shop, not
                // necessarily the one processing the return. Restoring it
                // into the wrong shop's batches, or decrementing
                // products.stock at the origin while crediting a totally
                // different shop's stock, would corrupt both sides' books.
                // Blocked with a clear message for now, same fallback
                // StockTransferController already uses for the same reason.
                if ($isCrossBranch && (! empty($item->batch_allocations) || $item->product_serial_id)) {
                    throw ValidationException::withMessages([
                        'lines' => "{$item->product_name}: এটি ব্যাচ/সিরিয়াল ট্র্যাক করা পণ্য — অন্য শাখার বিক্রি হওয়া এই ধরনের পণ্য এখনো ক্রস-ব্র্যাঞ্চ ফেরত নেওয়া যায় না। মূল শাখা থেকে প্রসেস করুন।",
                    ]);
                }

                // Same-branch return (the overwhelming majority): the
                // original product/variant row IS the target, exactly as
                // before. Cross-branch: resolve (or create, same
                // barcode-then-name match StockTransfer/CatalogSync already
                // use) the equivalent row in the RECEIVING shop's own
                // catalog — stock goes back to whichever branch is actually
                // taking the return, never the origin shop's inventory.
                [$targetProduct, $targetVariant] = $this->resolveReceivingProductAndVariant($item, $shopId, $isCrossBranch);

                if ($condition === 'resalable') {
                    if ($targetVariant) {
                        $targetVariant->applyStockDelta($returnQty);
                        $targetProduct->applyStockDelta($returnQty);
                    } else {
                        $targetProduct->applyStockDelta($returnQty * (float) $item->unit_factor);

                        if (! $isCrossBranch) {
                            // Batch stock restores proportionally for a
                            // partial return too (see
                            // BatchStock::restorePartial) — $priorBatchRestored
                            // is whatever an earlier partial return of this
                            // same line already put back, so two partial
                            // returns of one line never double-restore.
                            $priorBatchRestored = (float) SalesReturn::withoutGlobalScopes()->where('sale_item_id', $item->id)->where('condition', 'resalable')->sum('qty');
                            BatchStock::restorePartial($item->batch_allocations, $priorBatchRestored, $returnQty);

                            // A serial-tracked item is always sold one at a
                            // time (qty=1) — there's no such thing as a
                            // genuinely partial return of it, so only flip it
                            // back to in_stock when the whole line comes back.
                            if (abs($returnQty - (float) $item->qty) < 0.0001) {
                                SerialStock::restore($item->product_serial_id);
                            }
                        }
                    }
                } else {
                    // 'lost' and 'damaged' are the same write-off, differing
                    // only in why — see docs/FIX_PLAN.md's Phase 34 design note
                    $damageReason = $condition === 'lost'
                        ? 'কাস্টমার ফেরত — পার্সেল হারিয়ে গেছে'
                        : 'কাস্টমার ফেরত — বিক্রিযোগ্য না';
                    Damage::create([
                        'product_id' => $targetProduct->id,
                        'product_variant_id' => $targetVariant?->id,
                        'qty' => $returnQty,
                        'reason' => $damageReason.($isCrossBranch ? ' (অন্য শাখার বিক্রি)' : ''),
                        'loss' => $cost,
                        'date' => now()->toDateString(),
                    ]);
                }

                $returnRows[] = [
                    'item' => $item,
                    'target_product_id' => $targetProduct->id,
                    'target_variant_id' => $targetVariant?->id,
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
                    // the RECEIVING shop's own product/variant row — never
                    // the original sale_item's product_id/product_variant_id
                    // directly, which belongs to the sale's origin shop for a
                    // cross-branch return and would be a meaningless (or
                    // worse, a coincidentally-real-but-wrong) id in this
                    // shop's own products table
                    'product_id' => $row['target_product_id'],
                    'product_variant_id' => $row['target_variant_id'],
                    'sale_id' => $sale->id,
                    'sale_item_id' => $row['item']->id,
                    'customer_id' => $customer?->id,
                    'exchange_sale_id' => $exchangeSale?->id,
                    'user_id' => $userId,
                    'qty' => $row['qty'],
                    'refund' => $row['refund'],
                    'cost' => $row['cost'],
                    'condition' => $row['condition'],
                    'reason' => $data['reason'] ?? null,
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

            // Online order hook — see docs/FIX_PLAN.md's Phase 34 design
            // note. A FULL return (every sale item now fully returned)
            // flips the order to 'returned', regardless of which status it
            // was in before, as long as it isn't already terminal. A
            // partial return deliberately leaves the order's own status
            // untouched — it's an accounting event against the Sale, not a
            // new lifecycle state.
            // NOT OnlineOrder::TERMINAL_STATUSES — that list includes
            // 'delivered', which is exactly the normal starting point a
            // return is processed from, not a state this hook should skip
            $onlineOrder = OnlineOrder::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
            if ($onlineOrder && ! in_array($onlineOrder->status, ['returned', 'cancelled'], true)) {
                $allItems = SaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->get();
                $fullyReturned = $allItems->every(function (SaleItem $item) {
                    $returnedQty = (float) SalesReturn::withoutGlobalScopes()->where('sale_item_id', $item->id)->sum('qty');

                    return $returnedQty >= (float) $item->qty - 0.0001;
                });

                if ($fullyReturned) {
                    $onlineOrder->update(['status' => 'returned']);
                    Activity::log('online_order.returned', "অর্ডার '{$onlineOrder->order_number}' সম্পূর্ণ রিটার্ন হয়েছে।", $onlineOrder, ['reason' => $data['reason'] ?? null]);
                }
            }

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
     * Which Product/ProductVariant row in the RECEIVING shop's own catalog a
     * returned line's stock goes back into. Same shop it was sold at (the
     * overwhelming majority of returns, including every shop with no
     * branches at all): identical to the original sale_item's own
     * product_id/product_variant_id, unchanged. A sibling branch taking back
     * a sale made elsewhere: matched (barcode first, else name/size+color —
     * the same precedence CatalogSync/StockTransferController already use),
     * auto-created at stock 0 if the receiving branch has never carried this
     * product/variant before.
     *
     * @return array{0: Product, 1: ?ProductVariant}
     */
    private function resolveReceivingProductAndVariant(SaleItem $item, int $receivingShopId, bool $isCrossBranch): array
    {
        if (! $isCrossBranch) {
            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
            $variant = $item->product_variant_id ? ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first() : null;

            return [$product, $variant];
        }

        $originalProduct = Product::withoutGlobalScopes()->whereKey($item->product_id)->first();
        if (! $originalProduct) {
            abort(422, 'মূল পণ্যটি আর খুঁজে পাওয়া যাচ্ছে না।');
        }

        $product = Product::withoutGlobalScopes()->where('shop_id', $receivingShopId)
            ->where(fn ($q) => filled($originalProduct->barcode)
                ? $q->where('barcode', $originalProduct->barcode)->orWhere('name', $originalProduct->name)
                : $q->where('name', $originalProduct->name))
            ->lockForUpdate()->first();

        if (! $product) {
            $product = Product::create([
                'shop_id' => $receivingShopId,
                'name' => $originalProduct->name, 'name_en' => $originalProduct->name_en,
                'emoji' => $originalProduct->emoji, 'barcode' => $originalProduct->barcode,
                'cost' => $originalProduct->cost, 'price' => $originalProduct->price,
                'sold_by_weight' => $originalProduct->sold_by_weight, 'weight_unit' => $originalProduct->weight_unit,
                'stock' => 0,
            ]);
        }

        $variant = null;
        if ($item->product_variant_id) {
            $originalVariant = ProductVariant::withoutGlobalScopes()->whereKey($item->product_variant_id)->first();
            if ($originalVariant) {
                $variant = ProductVariant::withoutGlobalScopes()->where('shop_id', $receivingShopId)->where('product_id', $product->id)
                    ->where(fn ($q) => filled($originalVariant->barcode)
                        ? $q->where('barcode', $originalVariant->barcode)->orWhere(fn ($q2) => $q2->where('size', $originalVariant->size)->where('color', $originalVariant->color))
                        : $q->where('size', $originalVariant->size)->where('color', $originalVariant->color))
                    ->lockForUpdate()->first();

                if (! $variant) {
                    $variant = ProductVariant::create([
                        'shop_id' => $receivingShopId, 'product_id' => $product->id,
                        'size' => $originalVariant->size, 'color' => $originalVariant->color, 'barcode' => $originalVariant->barcode,
                        'price' => $originalVariant->price, 'cost' => $originalVariant->cost,
                        'stock' => 0,
                    ]);
                }
            }
        }

        return [$product, $variant];
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
                $variant->applyStockDelta(-$qty);
                $product->applyStockDelta(-$qty);
            } else {
                if ($product->variants_count > 0) {
                    abort(422, "{$product->name}-এর জন্য একটা সাইজ/রং বাছাই করুন।");
                }
                if ($product->stock < $qty) {
                    abort(422, "পর্যাপ্ত স্টক নেই: {$product->name}।");
                }
                $product->applyStockDelta(-$qty);
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

        // Confirmed real bug (see docs/FIX_PLAN.md Phase 3.1): $netSettlement
        // above is the correct NET figure, but until now it was only ever
        // used in memory to move cash/due — never persisted anywhere. Every
        // later read of this sale (invoice view, printed/WhatsApp memo, the
        // "split into installments" flow) recomputed due as a generic
        // `total - sum(payments)`, which — knowing nothing about the
        // trade-in — overstated it by exactly the credit amount. Recording
        // the credit as its own tender line (method=trade_credit, same
        // shape as any other SalePayment, so every existing sum(payments)
        // call site already handles it with zero special-casing) makes that
        // generic formula correct again with no other code needing to change.
        // min($credit, $newTotal): only the portion of the trade-in that was
        // actually applied toward THIS sale's total counts here — if the
        // traded-in item was worth more than the new item(s), the excess is
        // refunded/credited separately above, not "spent" on this sale.
        $creditApplied = round(min($credit, $newTotal), 2);
        if ($creditApplied > 0) {
            SalePayment::create([
                'shop_id' => $shopId,
                'sale_id' => $exchangeSale->id,
                'method' => 'trade_credit',
                'amount' => $creditApplied,
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
