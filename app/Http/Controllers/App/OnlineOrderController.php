<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreOnlineOrderRequest;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SalesChannel;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\BatchStock;
use App\Support\CourierCharge;
use App\Support\IngredientConsumption;
use App\Support\SaleReversal;
use App\Support\SerialStock;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "Online Business" module, part 2 — see docs/FIX_PLAN.md's Phase 32
 * design note. An online order's Sale/stock/payments are created
 * immediately (real decrement, exactly like POS checkout) but excluded
 * from every report (Sale.counted_in_reports) until Delivered.
 */
class OnlineOrderController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', '');
        $channelId = $request->get('channel_id');

        $orders = OnlineOrder::with(['channel', 'sale.customer'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($channelId, fn ($q) => $q->where('channel_id', $channelId))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('App/OnlineBusiness/Orders/Index', [
            'orders' => $orders,
            'channels' => SalesChannel::orderBy('name')->get(['id', 'name', 'platform_type']),
            'status' => $status,
            'channelId' => $channelId,
            'statuses' => OnlineOrder::STATUSES,
        ]);
    }

    public function create()
    {
        return Inertia::render('App/OnlineBusiness/Orders/Create', [
            'channels' => SalesChannel::where('active', true)->orderBy('name')->get(),
            'products' => Product::with('variants')->orderBy('name')->get(['id', 'name', 'emoji', 'price', 'discount_price', 'sold_by_weight']),
            'couriers' => Courier::where('active', true)->orderBy('name')->get(['id', 'name', 'charge_type']),
        ]);
    }

    /**
     * Repeat-customer / fake-order risk check — a warning, never a hard
     * block (see docs/FIX_PLAN.md's Phase 32 design note). Its own small
     * endpoint rather than extending the shared `customers.lookup` POS
     * already polls on every keystroke, so this computation only ever
     * runs for a shop actually using online_business.
     */
    public function customerRisk(Request $request)
    {
        $phone = trim((string) $request->get('phone', ''));
        $customer = $phone !== '' ? Customer::where('phone', $phone)->first() : null;

        if (! $customer) {
            return response()->json(['found' => false]);
        }

        $cancelledOrReturned = OnlineOrder::whereIn('sale_id', $customer->sales()->withVoided()->pluck('id'))
            ->whereIn('status', ['cancelled', 'returned'])
            ->count();
        $delivered = OnlineOrder::whereIn('sale_id', $customer->sales()->withVoided()->pluck('id'))
            ->where('status', 'delivered')
            ->count();

        return response()->json(['found' => true, 'cancelled_or_returned' => $cancelledOrReturned, 'delivered' => $delivered]);
    }

    /**
     * CSV import, step 1 — parse and validate only, nothing is written yet.
     * One product/qty per row (a multi-item order still goes through the
     * manual form — see docs/FIX_PLAN.md's Phase 32 design note on why
     * this is a deliberate, not accidental, scope cut). Same fopen/fgetcsv
     * parsing style as ProductImportController, no new dependency.
     */
    public function csvPreview(Request $request)
    {
        $request->validate([
            'channel_id' => ['required', Rule::exists('sales_channels', 'id')->where('shop_id', Tenancy::id())],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $channel = SalesChannel::findOrFail($request->input('channel_id'));

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = $handle ? fgetcsv($handle) : false;
        if (! $header) {
            abort(422, 'ফাইলটি পড়া যায়নি বা খালি।');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $columns = array_map(fn ($h) => strtolower(trim($h)), $header);

        $rows = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = [];
            foreach ($columns as $i => $col) {
                $data[$col] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $result = [
                'row' => $rowNum, 'phone' => $data['phone'] ?? '', 'name' => $data['name'] ?? '',
                'product_query' => $data['product'] ?? '', 'qty' => $data['qty'] ?? '',
                'delivery_charge' => $data['delivery_charge'] ?? '0', 'cod_amount' => $data['cod_amount'] ?? '0',
                'external_reference' => $data['external_reference'] ?? '',
                'ok' => true, 'error' => null, 'product_id' => null, 'product_name' => null,
            ];

            if ($result['phone'] === '' || ! is_numeric($result['qty']) || (float) $result['qty'] <= 0) {
                $result['ok'] = false;
                $result['error'] = 'ফোন/পরিমাণ ঠিক নেই';
            } else {
                $product = Product::where('barcode', $result['product_query'])->first()
                    ?? Product::where('name', $result['product_query'])->first();
                if (! $product) {
                    $result['ok'] = false;
                    $result['error'] = "পণ্য পাওয়া যায়নি: {$result['product_query']}";
                } else {
                    $result['product_id'] = $product->id;
                    $result['product_name'] = $product->name;
                }
            }

            if ($result['ok'] && $result['external_reference'] !== ''
                && OnlineOrder::where('channel_id', $channel->id)->where('external_reference', $result['external_reference'])->exists()) {
                $result['ok'] = false;
                $result['error'] = 'এই অর্ডার আগেই ইমপোর্ট করা হয়েছে (ডুপ্লিকেট)';
            }

            $rows[] = $result;
        }
        fclose($handle);

        return response()->json(['rows' => $rows]);
    }

    /** CSV import, step 2 — the owner has reviewed the preview and kept only the rows they want; each becomes a real order via the exact same path the manual form uses. */
    public function csvImport(Request $request)
    {
        $data = $request->validate([
            'channel_id' => ['required', Rule::exists('sales_channels', 'id')->where('shop_id', Tenancy::id())],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.phone' => ['required', 'string'],
            'rows.*.name' => ['nullable', 'string'],
            'rows.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'rows.*.qty' => ['required', 'numeric', 'min:0.001'],
            'rows.*.delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'rows.*.cod_amount' => ['nullable', 'numeric', 'min:0'],
            'rows.*.external_reference' => ['nullable', 'string'],
        ]);

        $shopId = Tenancy::id();
        $created = 0;
        $failed = [];

        foreach ($data['rows'] as $row) {
            try {
                $this->createOrder([
                    'channel_id' => $data['channel_id'],
                    'customer_phone' => $row['phone'],
                    'customer_name' => $row['name'] ?? '',
                    'items' => [['product_id' => $row['product_id'], 'qty' => (float) $row['qty']]],
                    'delivery_charge' => (float) ($row['delivery_charge'] ?? 0),
                    'cod_amount' => (float) ($row['cod_amount'] ?? 0),
                    'external_reference' => $row['external_reference'] ?: null,
                ], $shopId);
                $created++;
            } catch (ValidationException $e) {
                $failed[] = "সারি (ফোন {$row['phone']}): ".($e->validator->errors()->first() ?: $e->getMessage());
            } catch (\Throwable $e) {
                $failed[] = "সারি (ফোন {$row['phone']}): ".$e->getMessage();
            }
        }

        $summary = "{$created}টি অর্ডার তৈরি হয়েছে।";
        if ($failed) {
            $summary .= ' ব্যর্থ '.count($failed).'টি: '.implode('; ', array_slice($failed, 0, 5));
        }

        return back()->with($created > 0 ? 'success' : 'error', $summary);
    }

    public function show(OnlineOrder $onlineOrder)
    {
        $this->authorize('view', $onlineOrder);

        return Inertia::render('App/OnlineBusiness/Orders/Show', [
            'order' => $onlineOrder->load(['channel', 'courier', 'sale' => fn ($q) => $q->withVoided()->with(['items', 'payments', 'customer'])]),
            'couriers' => Courier::where('active', true)->orderBy('name')->get(['id', 'name', 'charge_type']),
            'history' => ActivityLog::where('subject_type', OnlineOrder::class)
                ->where('subject_id', $onlineOrder->id)
                ->with('user:id,name')
                ->orderByDesc('id')
                ->get(['id', 'description', 'meta', 'user_id', 'created_at']),
        ]);
    }

    /**
     * Resolve-or-create by phone — identical pattern to PosController's own
     * customer handling, since an online customer is a normal Customer
     * (phone lookup, shared across branches) per the module's own ground
     * rule.
     */
    private function resolveCustomer(string $phone, string $name, int $shopId): Customer
    {
        $customer = Customer::where('phone', $phone)->lockForUpdate()->first();

        if (! $customer) {
            return Customer::create(['shop_id' => $shopId, 'name' => $name ?: 'Customer', 'phone' => $phone, 'due' => 0]);
        }

        if ($name !== '' && $customer->name !== $name) {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }

    public function store(StoreOnlineOrderRequest $request)
    {
        $onlineOrder = $this->createOrder($request->validated(), Tenancy::id());

        return redirect()->route('app.onlineOrders.show', $onlineOrder->id)->with('success', "অর্ডার '{$onlineOrder->order_number}' তৈরি করা হয়েছে।");
    }

    /**
     * A business-rule rejection (stock, variant, etc.) during order
     * creation — thrown as a real ValidationException, never a plain
     * abort(422, ...). Laravel/Inertia only know how to render a proper
     * inline form error for a ValidationException; a bare abort() response
     * isn't shaped like an Inertia page response at all, so the client
     * falls back to its generic "Oops! An error occurred" crash screen
     * instead of showing the actual Bangla message — confirmed live
     * (2026-10-25) when a stock-shortage rejection crashed Khaled's
     * Create-order form instead of showing why.
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => [$message]]);
    }

    /**
     * The actual creation — shared by store() (one order from the manual
     * form) and the CSV import commit step (many orders, one per valid
     * row), so the two can never drift apart. $data is the exact shape
     * StoreOnlineOrderRequest validates.
     */
    private function createOrder(array $data, int $shopId): OnlineOrder
    {
        return DB::transaction(function () use ($data, $shopId) {
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();
            $channel = SalesChannel::whereKey($data['channel_id'])->lockForUpdate()->first();

            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::whereIn('id', $productIds)->withCount('variants')->lockForUpdate()->get()->keyBy('id');

            $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique();
            $variants = $variantIds->isEmpty() ? collect() : ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

            $batchTrackingOn = $shop->hasFeature('batch_tracking');
            $serialTrackingOn = $shop->hasFeature('serial_tracking');

            $subtotal = 0;
            $profit = 0;
            $lines = [];

            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    $this->fail('পণ্যটি খুঁজে পাওয়া যায়নি।');
                }

                $variant = ! empty($item['product_variant_id']) ? $variants->get($item['product_variant_id']) : null;
                if ($product->variants_count > 0 && ! $variant) {
                    $this->fail("{$product->name}-এর জন্য একটা সাইজ/রং বাছাই করুন।");
                }
                if ($variant && $variant->product_id !== $product->id) {
                    $this->fail("এই ভ্যারিয়েন্টটি {$product->name}-এর জন্য সঠিক নয়।");
                }

                $qty = (float) $item['qty'];
                if (! $product->sold_by_weight && floor($qty) != $qty) {
                    $this->fail("{$product->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
                }

                if ($variant) {
                    if ((float) $variant->stock < $qty) {
                        $this->fail("পর্যাপ্ত স্টক নেই: {$product->name} (আছে {$variant->stock}, লাগবে {$qty})।");
                    }
                    $unitPrice = $variant->effectivePrice();
                    $unitCost = $variant->effectiveCost();
                } else {
                    if ((float) $product->stock < $qty) {
                        $this->fail("পর্যাপ্ত স্টক নেই: {$product->name} (আছে {$product->stock}, লাগবে {$qty})।");
                    }
                    if ($batchTrackingOn && BatchStock::sellableQty($product) < $qty) {
                        $this->fail("{$product->name}-এর পর্যাপ্ত অ-মেয়াদোত্তীর্ণ স্টক নেই।");
                    }
                    $unitPrice = $channel->priceForProduct($product);
                    $unitCost = (float) $product->cost;
                }

                $lineTotal = $unitPrice * $qty;
                $subtotal += $lineTotal;
                $profit += ($unitPrice - $unitCost) * $qty;

                $lines[] = [
                    'product' => $product, 'variant' => $variant, 'qty' => $qty,
                    'price' => $unitPrice, 'cost' => $unitCost,
                    'imei' => empty($item['imei']) ? null : trim($item['imei']),
                ];
            }

            $customer = $this->resolveCustomer(trim($data['customer_phone']), trim($data['customer_name'] ?? ''), $shopId);

            $deliveryCharge = (float) ($data['delivery_charge'] ?? 0);
            $total = $subtotal + $deliveryCharge;

            $shop->online_invoice_counter += 1;
            $invoiceNo = 'OL-'.$shop->online_invoice_counter;

            $sale = Sale::create([
                'shop_id' => $shopId,
                'customer_id' => $customer->id,
                'invoice_no' => $invoiceNo,
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'subtotal' => $subtotal,
                'discount' => 0,
                'service_charge' => $deliveryCharge,
                'vat' => 0,
                'total' => $total,
                'profit' => $profit,
                'payment_mode' => 'split',
                'sale_type' => 'online',
                // not yet counted anywhere — see Sale::booted()'s scope;
                // flips true only once this order is Delivered
                'counted_in_reports' => false,
            ]);

            foreach ($lines as $line) {
                $batchAllocations = null;
                $serial = null;

                if ($line['variant']) {
                    ProductVariant::whereKey($line['variant']->id)->decrement('stock', $line['qty']);
                    $line['product']->decrement('stock', $line['qty']);
                } else {
                    $line['product']->decrement('stock', $line['qty']);
                    IngredientConsumption::apply($line['product'], $line['qty']);

                    $batchAllocations = ($batchTrackingOn && ! $line['product']->sold_by_weight)
                        ? BatchStock::deduct($line['product'], (int) $line['qty'])
                        : null;

                    if ($serialTrackingOn && $line['imei'] && ! $line['product']->sold_by_weight) {
                        $serial = SerialStock::sell($line['product'], $line['imei']);
                    }
                }

                SaleItem::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'product_serial_id' => $serial?->id,
                    'product_name' => $line['product']->name,
                    'unit_factor' => 1,
                    'qty' => $line['qty'],
                    'price' => $line['price'],
                    'discount' => 0,
                    'cost' => $line['cost'],
                    'batch_allocations' => $batchAllocations,
                ]);
            }

            // payments — a genuinely-prepaid portion really is already in
            // the shop's account (bumps cash/bank_balance normally); the
            // COD portion is a tender line that explicitly does NOT, until
            // Part 3's settlement actually reconciles it (see SaleReversal
            // for the matching void-side exception)
            $paidAmount = (float) ($data['paid_amount'] ?? 0);
            $codAmount = (float) ($data['cod_amount'] ?? 0);

            if ($paidAmount > 0) {
                SalePayment::create(['shop_id' => $shopId, 'sale_id' => $sale->id, 'method' => $data['paid_method'], 'amount' => $paidAmount]);
                if ($data['paid_method'] === 'cash') {
                    $shop->cash_balance += $paidAmount;
                } else {
                    $shop->bank_balance += $paidAmount;
                }
            }
            if ($codAmount > 0) {
                SalePayment::create(['shop_id' => $shopId, 'sale_id' => $sale->id, 'method' => 'cod', 'amount' => $codAmount]);
            }

            $dueRemainder = max(0, $total - $paidAmount - $codAmount);
            if ($dueRemainder > 0) {
                $customer->increment('due', $dueRemainder);
            }

            $shop->save();

            $channel->order_counter += 1;
            $orderNumber = $channel->order_prefix.$channel->order_counter;
            $channel->save();

            // courier is optional here — explicit pick, else the channel's
            // own default (Part 1), else none yet (can still be set at the
            // "handed to courier" step) — see docs/FIX_PLAN.md's Phase 33
            // design note
            $courierId = $data['courier_id'] ?? $channel->default_courier_id;
            $courier = $courierId ? Courier::find($courierId) : null;
            $parcelWeight = isset($data['parcel_weight']) ? (float) $data['parcel_weight'] : null;
            $isOutsideDhaka = array_key_exists('is_outside_dhaka', $data) ? (bool) $data['is_outside_dhaka'] : null;

            $onlineOrder = OnlineOrder::create([
                'shop_id' => $shopId,
                'channel_id' => $channel->id,
                'sale_id' => $sale->id,
                'order_number' => $orderNumber,
                'external_reference' => $data['external_reference'] ?? null,
                'status' => 'pending',
                'delivery_charge' => $deliveryCharge,
                'cod_amount' => $codAmount,
                'campaign_tag' => $data['campaign_tag'] ?? null,
                'courier_id' => $courier?->id,
                'courier_charge' => $courier ? CourierCharge::estimate($courier, $parcelWeight, $isOutsideDhaka) : null,
                'parcel_weight' => $parcelWeight,
                'is_outside_dhaka' => $isOutsideDhaka,
            ]);

            Activity::log('online_order.created', "অনলাইন অর্ডার '{$orderNumber}' তৈরি করা হয়েছে ({$channel->name})।", $onlineOrder, ['total' => $total]);

            return $onlineOrder;
        });
    }

    /** Simple forward moves — audit-only, no stock/money effect. */
    public function transition(Request $request, OnlineOrder $onlineOrder)
    {
        $this->authorize('update', $onlineOrder);

        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'packed', 'handed_to_courier', 'in_transit', 'failed', 'returning'])],
            'tracking_id' => ['nullable', 'string', 'max:100'],
            // only ever meaningful if a courier wasn't already picked at
            // order creation — "handed to courier" is the latest natural
            // point to still name one (see docs/FIX_PLAN.md's Phase 33
            // design note)
            'courier_id' => ['nullable', Rule::exists('couriers', 'id')->where('shop_id', Tenancy::id())],
            'parcel_weight' => ['nullable', 'numeric', 'min:0'],
            'is_outside_dhaka' => ['nullable', 'boolean'],
        ]);

        if (! $onlineOrder->canTransitionTo($data['status'])) {
            return back()->with('error', "'{$onlineOrder->status}' থেকে '{$data['status']}'-এ যাওয়া সম্ভব নয়।");
        }

        $from = $onlineOrder->status;
        $fields = [
            'status' => $data['status'],
            'tracking_id' => $data['tracking_id'] ?? $onlineOrder->tracking_id,
        ];

        if (! $onlineOrder->courier_id && ! empty($data['courier_id'])) {
            $courier = Courier::find($data['courier_id']);
            $parcelWeight = isset($data['parcel_weight']) ? (float) $data['parcel_weight'] : null;
            $isOutsideDhaka = array_key_exists('is_outside_dhaka', $data) ? (bool) $data['is_outside_dhaka'] : null;

            $fields['courier_id'] = $courier->id;
            $fields['courier_charge'] = CourierCharge::estimate($courier, $parcelWeight, $isOutsideDhaka);
            $fields['parcel_weight'] = $parcelWeight;
            $fields['is_outside_dhaka'] = $isOutsideDhaka;
        }

        $onlineOrder->update($fields);

        Activity::log('online_order.status', "অর্ডার '{$onlineOrder->order_number}' স্ট্যাটাস: {$from} → {$data['status']}", $onlineOrder, ['from' => $from, 'to' => $data['status']]);

        return back()->with('success', 'স্ট্যাটাস আপডেট করা হয়েছে।');
    }

    /**
     * The one transition with real side effects besides Cancel/Return —
     * flips visibility into every report, credits lifetime customer stats
     * and loyalty/referral, exactly once. See docs/FIX_PLAN.md's Phase 32
     * design note for why these specifically wait for Delivered while
     * stock/payments were already real from the moment the order was placed.
     */
    public function deliver(OnlineOrder $onlineOrder)
    {
        $this->authorize('update', $onlineOrder);

        if (! $onlineOrder->canTransitionTo('delivered')) {
            return back()->with('error', "'{$onlineOrder->status}' থেকে ডেলিভারি সম্পন্ন করা সম্ভব নয়।");
        }

        DB::transaction(function () use ($onlineOrder) {
            $shop = Shop::whereKey($onlineOrder->shop_id)->lockForUpdate()->first();
            $sale = Sale::withVoided()->with('customer')->findOrFail($onlineOrder->sale_id);
            $customer = $sale->customer_id ? Customer::whereKey($sale->customer_id)->lockForUpdate()->first() : null;

            // evaluated BEFORE this sale becomes visible, so it only ever
            // counts an earlier, already-delivered sale — never this one
            $isCustomersFirstSale = $customer && ! Sale::where('customer_id', $customer->id)->exists();

            $pointsEarned = 0;
            if ($customer && $shop->hasFeature('loyalty_points') && (float) $shop->loyalty_earn_rate > 0) {
                $pointsEarned = (int) floor((float) $sale->total / 100 * (float) $shop->loyalty_earn_rate);
            }

            $sale->update([
                'counted_in_reports' => true,
                'points_earned' => $pointsEarned ?: null,
            ]);

            if ($customer) {
                $customer->increment('total_spent', (float) $sale->total);
                $customer->increment('visits');
                if ($pointsEarned) {
                    $customer->increment('loyalty_points', $pointsEarned);
                }

                if ($isCustomersFirstSale && $customer->referred_by_customer_id && ! $customer->referral_rewarded_at
                    && $shop->hasFeature('customer_referrals') && $shop->hasFeature('loyalty_points') && (int) $shop->referral_bonus_points > 0) {
                    $referrer = Customer::whereKey($customer->referred_by_customer_id)->lockForUpdate()->first();
                    if ($referrer) {
                        $referrer->increment('loyalty_points', (int) $shop->referral_bonus_points);
                        $customer->referral_rewarded_at = now();
                        $customer->save();
                    }
                }
            }

            $onlineOrder->update(['status' => 'delivered', 'delivered_at' => now()]);

            Activity::log('online_order.delivered', "অর্ডার '{$onlineOrder->order_number}' ডেলিভার সম্পন্ন হয়েছে।", $onlineOrder, ['total' => (float) $sale->total]);
        });

        return back()->with('success', 'ডেলিভারি সম্পন্ন — এখন রিপোর্টে যুক্ত হয়েছে।');
    }

    /** Cancel before delivery — reuses the exact same reversal the owner-void route and the admin hard-delete tool already use. */
    public function cancel(Request $request, OnlineOrder $onlineOrder)
    {
        $this->authorize('update', $onlineOrder);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        if (! $onlineOrder->canTransitionTo('cancelled')) {
            return back()->with('error', "'{$onlineOrder->status}' অবস্থায় থাকা অর্ডার বাতিল করা সম্ভব নয়।");
        }

        DB::transaction(function () use ($onlineOrder, $data) {
            $shop = Shop::whereKey($onlineOrder->shop_id)->lockForUpdate()->first();
            $sale = Sale::withVoided()->with(['items', 'payments'])->findOrFail($onlineOrder->sale_id);

            SaleReversal::reverse($sale, $shop);
            $sale->update(['voided_at' => now(), 'voided_reason' => $data['reason'], 'voided_by' => Auth::guard('web')->id()]);
            $onlineOrder->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            Activity::log('online_order.cancelled', "অর্ডার '{$onlineOrder->order_number}' বাতিল করা হয়েছে — কারণ: {$data['reason']}", $onlineOrder, ['reason' => $data['reason']]);
        });

        return back()->with('success', 'অর্ডার বাতিল করা হয়েছে — স্টক ও ব্যালেন্স ফিরিয়ে দেওয়া হয়েছে।');
    }

}
