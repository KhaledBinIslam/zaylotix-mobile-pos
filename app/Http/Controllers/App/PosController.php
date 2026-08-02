<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CheckoutRequest;
use App\Models\Customer;
use App\Models\GatewayPayment;
use App\Models\HeldCart;
use App\Models\PaymentGatewayCredential;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Support\PromotionEngine;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PosController extends Controller
{
    public function index(Request $request)
    {
        $shop = Tenancy::shop();

        $products = Product::with(['category', 'unit', 'productUnits.unit', 'variants'])
            ->orderByDesc('id')
            ->get();

        $categories = ProductCategory::orderBy('name')->get();

        // an open quotation being converted to a real sale — the cart is
        // prefilled client-side from this, but the actual stock/money
        // movement still only ever happens through the normal checkout()
        // below, never here
        $quotation = null;
        if ($request->filled('quotation')) {
            $quotation = Quotation::with('items')->where('status', 'open')->find($request->get('quotation'));
        }

        return Inertia::render('App/Pos/Index', [
            'products' => $products,
            'categories' => $categories,
            'salesMode' => $shop->sales_mode,
            // full cart_data isn't needed until actually resumed — this list
            // is just enough to show a picker (label + how long it's been held)
            'heldCarts' => HeldCart::orderByDesc('id')->get(['id', 'label', 'created_at'])
                ->map(fn ($h) => ['id' => $h->id, 'label' => $h->label, 'created_at' => $h->created_at->diffForHumans()]),
            'prefillQuotation' => $quotation,
            // just which providers are usable — never the credentials
            // themselves, so this is safe to expose to a cashier session
            // too (they're the ones actually running checkout)
            'activeGatewayProviders' => PaymentGatewayCredential::where('shop_id', $shop->id)->where('is_active', true)->pluck('provider'),
        ]);
    }

    /**
     * Create a sale atomically: lock every line's product row (consistent
     * ascending id order to avoid deadlocks under concurrent checkouts),
     * verify stock, decrement it, snapshot prices/costs onto sale_items,
     * update the customer ledger, and bump the shop's cash/bank balance —
     * all inside one transaction so a mid-way failure never leaves stock
     * decremented without a recorded sale, or a sale without stock movement.
     *
     * Each line may optionally name a `product_unit_id` (a pack size like
     * "box" or "strip" — see product_units table) instead of the product's
     * base unit. The price and cost are always resolved server-side from
     * that pack's own price and its factor × base cost — never trusted from
     * the client — and stock is always decremented in base units (qty × factor)
     * so a sale of "1 box" and a sale of "100 pieces" leave stock identical.
     */
    public function checkout(CheckoutRequest $request)
    {
        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();
        $sale = self::performCheckout($request->validated(), $shopId, $userId);

        return response()->json(['sale' => $sale]);
    }

    /**
     * The one place a real Sale gets created from a priced cart — shared by
     * the normal authenticated checkout() above and GatewayWebhookController,
     * which calls this the instant a payment-gateway webhook confirms money
     * genuinely arrived (never on the strength of a browser redirect alone).
     * Everything here is byte-for-byte what checkout() always did; this is a
     * pure extraction, not a behavior change, specifically so the two
     * callers can never compute a sale differently from each other.
     *
     * @param  array<int,array{method:string,amount:float}>|null  $forcedPayments  when given, replaces $data['payments'] entirely — used by the gateway webhook path, where the "payment" is the gateway capture itself, never a client-submitted tender.
     * @param  bool  $autoCoverTotal  used only by GatewayCheckoutController's price preview (see App\Support\Gateways\GatewayPricePreview) — skips the tendered/due-remainder checks entirely and synthesizes a single payment covering the total exactly, since the whole point of that call is to run this same pricing/stock-validation logic and then roll it back just to learn what the total actually is, before a real payment exists to attach.
     */
    public static function performCheckout(array $data, int $shopId, ?int $userId, ?array $forcedPayments = null, bool $autoCoverTotal = false): Sale
    {
        return DB::transaction(function () use ($data, $shopId, $userId, $forcedPayments, $autoCoverTotal) {
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            // this checkout is converting an open quotation — locked and
            // re-checked here (not just once at page-load) so a double-tap
            // or a second tab can't convert the same quote into two sales
            $quotation = null;
            if (! empty($data['quotation_id'])) {
                if (! $shop->hasFeature('quotations')) {
                    abort(422, 'কোটেশন ফিচারটি চালু নেই।');
                }
                $quotation = Quotation::whereKey($data['quotation_id'])->lockForUpdate()->first();
                if (! $quotation || $quotation->status !== 'open') {
                    abort(422, 'এই কোটেশনটি ইতিমধ্যে বিক্রয়ে রূপান্তরিত বা বাতিল হয়ে গেছে।');
                }
            }

            $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values();

            $products = Product::whereIn('id', $productIds)
                ->withCount('variants')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $unitIds = collect($data['items'])->pluck('product_unit_id')->filter()->unique();
            $productUnits = $unitIds->isEmpty()
                ? collect()
                : ProductUnit::whereIn('id', $unitIds)->with('unit')->get()->keyBy('id');

            // Variants (size/color) have their own independent stock, unlike
            // product_units — lock them too so a variant's stock can't be
            // oversold by a concurrent checkout any more than the product
            // itself can.
            $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique();
            $productVariants = $variantIds->isEmpty()
                ? collect()
                : ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            $profit = 0;
            $lines = [];

            // Tracks stock left to allocate per product as lines are
            // validated, since a single cart can carry more than one line
            // for the same product (e.g. "1 box" + "3 loose pieces" of the
            // same item) — checking each line against $product->stock alone
            // would let every line pass individually while their combined
            // base-unit total oversells the actual stock.
            $stockRemaining = $products->map(fn ($p) => $p->stock);
            // same idea, one level down — a cart can carry two lines of the
            // same variant too (rare, but the same oversell trap applies)
            $variantStockRemaining = $productVariants->map(fn ($v) => $v->stock);

            // computed once, up front — needed here (not just later at
            // deduction time) so an expired-batch shortfall rejects the
            // whole sale before anything is written, the same way a plain
            // stock shortfall does
            $batchTrackingOn = $shop->hasFeature('batch_tracking');
            $sellableRemaining = $batchTrackingOn
                ? $products->map(fn ($p) => \App\Support\BatchStock::sellableQty($p))
                : collect();

            // BOGO/combo promotions (buy N of A, get M of B free/discounted)
            // auto-apply here — computed once against the raw item list
            // before the per-line loop below folds it into each matching
            // line's own discount, same as a manually-typed one would be.
            // Gated the same way batch/serial tracking are — a shop whose
            // 'promotions' feature was later revoked stops having its
            // leftover promotion rows take effect, without needing to
            // delete them.
            $bogoResult = $shop->hasFeature('promotions')
                ? PromotionEngine::computeAutoLineDiscounts($data['items'], $shopId)
                : ['discounts' => [], 'triggeredIds' => []];
            $autoDiscounts = $bogoResult['discounts'];

            // whole-cart toggle, not per-line — a product with no
            // wholesale_price of its own just always sells at its normal
            // price regardless of this flag
            $isWholesale = $shop->hasFeature('wholesale_pricing') && ($data['sale_type'] ?? 'retail') === 'wholesale';

            foreach ($data['items'] as $index => $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    abort(422, 'পণ্যটি খুঁজে পাওয়া যায়নি — হয়তো এই মুহূর্তে মুছে ফেলা হয়েছে। তালিকা রিলোড করে আবার চেষ্টা করুন।');
                }

                $factor = 1;
                $unitPrice = ($isWholesale && $product->wholesale_price !== null) ? (float) $product->wholesale_price : (float) $product->price;
                $unitCost = $product->cost;
                $unitLabel = null;
                $variant = null;
                $variantLabel = null;

                if (! empty($item['product_unit_id']) && ! empty($item['product_variant_id'])) {
                    abort(422, "একই লাইনে প্যাক সাইজ ও ভ্যারিয়েন্ট দুটোই বাছাই করা যাবে না।");
                }

                // Fractional qty only makes sense on a weighed product's own
                // base-unit line — a pack size (factor is a whole multiple)
                // or a variant (always whole units) never allows it, and
                // neither does a plain (non-weighed) product line.
                $isWeighedBaseLine = $product->sold_by_weight && empty($item['product_unit_id']) && empty($item['product_variant_id']);
                if (! $isWeighedBaseLine && floor($item['qty']) != $item['qty']) {
                    abort(422, "{$product->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।");
                }

                // A product with variants must never be sold "in general" —
                // products.stock only means anything as the sum of its
                // variants, so a sale that doesn't name which variant it
                // came from would decrement the total without ever
                // decrementing a specific variant, breaking that invariant.
                if ($product->variants_count > 0 && empty($item['product_variant_id'])) {
                    abort(422, "{$product->name}-এর জন্য একটা সাইজ/রং বাছাই করুন।");
                }

                if (! empty($item['product_unit_id'])) {
                    $pu = $productUnits->get($item['product_unit_id']);
                    if (! $pu || $pu->product_id !== $product->id) {
                        abort(422, "এই এককটি {$product->name}-এর জন্য সঠিক নয়। পেজ রিলোড করে আবার চেষ্টা করুন।");
                    }
                    $factor = $pu->factor;
                    $unitPrice = $pu->price;
                    $unitCost = $product->cost * $factor;
                    $unitLabel = $pu->unit->name;
                } elseif (! empty($item['product_variant_id'])) {
                    $variant = $productVariants->get($item['product_variant_id']);
                    if (! $variant || $variant->product_id !== $product->id) {
                        abort(422, "এই ভ্যারিয়েন্টটি {$product->name}-এর জন্য সঠিক নয়। পেজ রিলোড করে আবার চেষ্টা করুন।");
                    }
                    $unitPrice = $variant->effectivePrice();
                    $unitCost = $variant->effectiveCost();
                    $variantLabel = $variant->label();
                }

                $baseQtyNeeded = $item['qty'] * $factor;

                if ($variant) {
                    if ($variantStockRemaining[$variant->id] < $item['qty']) {
                        abort(422, "পর্যাপ্ত স্টক নেই: {$product->name} ({$variantLabel}) (আছে {$variantStockRemaining[$variant->id]}, লাগবে {$item['qty']})।");
                    }
                    $variantStockRemaining[$variant->id] -= $item['qty'];
                } elseif ($stockRemaining[$product->id] < $baseQtyNeeded) {
                    abort(422, "পর্যাপ্ত স্টক নেই: {$product->name} (আছে {$stockRemaining[$product->id]}, লাগবে {$baseQtyNeeded})।");
                } elseif ($batchTrackingOn && $sellableRemaining[$product->id] < $baseQtyNeeded) {
                    // real stock exists, but not enough of it outside an
                    // expired batch — reject outright rather than letting
                    // deduct() silently under-cover the allocation the way
                    // an ordinary untracked-stock shortfall is allowed to
                    abort(422, "{$product->name}-এর পর্যাপ্ত অ-মেয়াদোত্তীর্ণ স্টক নেই — কিছু স্টক এক্সপায়ার্ড ব্যাচে আটকে আছে (বিক্রিযোগ্য আছে {$sellableRemaining[$product->id]}, লাগবে {$baseQtyNeeded})।");
                } else {
                    $stockRemaining[$product->id] -= $baseQtyNeeded;
                    if ($batchTrackingOn) {
                        $sellableRemaining[$product->id] -= $baseQtyNeeded;
                    }
                }

                $rawLineTotal = $unitPrice * $item['qty'];
                // per-line discount is a flat taka amount, never more than
                // the line's own total — never trust the client's arithmetic.
                // Auto-applied BOGO/combo value (if this line is a matching
                // reward line) stacks on top of whatever the cashier typed.
                $lineDiscount = min((float) ($item['discount'] ?? 0) + ($autoDiscounts[$index] ?? 0), $rawLineTotal);
                $lineTotal = $rawLineTotal - $lineDiscount;
                $lineProfit = ($unitPrice - $unitCost) * $item['qty'] - $lineDiscount;

                $subtotal += $lineTotal;
                $profit += $lineProfit;

                $lines[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'qty' => $item['qty'],
                    'price' => $unitPrice,
                    'discount' => $lineDiscount,
                    'cost' => $unitCost,
                    'unit_label' => $unitLabel,
                    'variant_label' => $variantLabel,
                    'unit_factor' => $factor,
                    'base_qty' => $baseQtyNeeded,
                    'imei' => empty($item['imei']) ? null : trim($item['imei']),
                ];
            }

            // resolve / create customer — moved ahead of discount computation
            // because loyalty-point redemption (below) needs to know who's
            // paying before the final discount/total can be settled. An
            // existing customer is locked so a redemption's "does the
            // balance actually cover this" check can't race a concurrent
            // checkout the same way the purchases/supplier-return fixes
            // this session had to guard against.
            $customer = null;
            $phone = trim($data['customer_phone'] ?? '');
            $name = trim($data['customer_name'] ?? '');

            if ($phone !== '') {
                $customer = Customer::where('phone', $phone)->lockForUpdate()->first();
                if (! $customer) {
                    $customer = Customer::create([
                        'shop_id' => $shopId,
                        'name' => $name ?: 'Customer',
                        'phone' => $phone,
                        'due' => 0,
                    ]);
                } elseif ($name !== '' && $customer->name !== $name) {
                    $customer->update(['name' => $name]);
                }
            }

            // loyalty point redemption — a flat taka discount, same as a
            // coupon, but paid for out of the customer's own point balance
            // instead of the shop's margin
            $redeemPoints = (int) ($data['redeem_points'] ?? 0);
            $loyaltyDiscount = 0.0;
            if ($redeemPoints > 0) {
                if (! $shop->hasFeature('loyalty_points')) {
                    abort(422, 'লয়্যালটি পয়েন্ট ফিচারটি চালু নেই।');
                }
                if (! $customer) {
                    abort(422, 'পয়েন্ট রিডিম করতে কাস্টমারের ফোন নম্বর দিন।');
                }
                if ($redeemPoints > $customer->loyalty_points) {
                    abort(422, "এত পয়েন্ট নেই। বর্তমান পয়েন্ট: {$customer->loyalty_points}।");
                }
                $loyaltyDiscount = round($redeemPoints * (float) $shop->loyalty_point_value, 2);
            }

            // coupon discount stacks on top of the manual overall discount
            // and any point redemption, all clamped together so nothing can
            // push the bill negative
            $couponResult = $shop->hasFeature('promotions')
                ? PromotionEngine::resolveCoupon($data['coupon_code'] ?? null, $subtotal)
                : ['discount' => 0.0, 'promotion' => null];
            // free/staff-meal sale — the whole bill is comped, tracked
            // separately from a manual discount (see Reports::complimentaryReport()).
            // Forcing discount = subtotal here means $total lands on 0 and
            // flows through every check below (tender validation, due-needs-
            // customer, payment_mode) exactly like any other fully-discounted
            // sale already does — no special-casing needed past this point.
            $isComplimentary = (bool) ($data['complimentary'] ?? false);
            $manualDiscount = (float) ($data['discount'] ?? 0);
            $discount = $isComplimentary ? $subtotal : min($manualDiscount + $couponResult['discount'] + $loyaltyDiscount, $subtotal);
            $total = $subtotal - $discount;
            $profit -= $discount;

            $vat = 0;
            if ($shop->vat_mode === 'full') {
                $vat = round($total * 15 / 115, 2);
            } elseif ($shop->vat_mode === 'turnover') {
                $vat = round($total * (float) $shop->turnover_rate / 100, 2);
            }

            // unlike VAT above (which is backed out of $total for display —
            // Bangladeshi retail prices are already VAT-inclusive), a
            // restaurant service charge is genuinely additive: it's a
            // percentage added ON TOP of the bill, so it must be folded into
            // $total before payments/due are computed below
            $serviceCharge = 0;
            if ($shop->service_charge_rate !== null) {
                $serviceCharge = round($total * (float) $shop->service_charge_rate / 100, 2);
                $total += $serviceCharge;
            }

            // points earned on what was actually paid (post every
            // discount, including any just-redeemed points) — never on the
            // pre-discount subtotal, or redeeming-then-immediately-earning
            // on the same taka would let points regenerate themselves
            $pointsEarned = 0;
            if ($customer && $shop->hasFeature('loyalty_points') && (float) $shop->loyalty_earn_rate > 0) {
                $pointsEarned = (int) floor($total / 100 * (float) $shop->loyalty_earn_rate);
            }

            if ($autoCoverTotal) {
                // price-preview mode — see the autoCoverTotal docblock above;
                // no real tender exists yet, so this synthesizes one that
                // exactly covers $total and skips every guard that only
                // makes sense once real money/tenders are involved
                $payments = collect([['method' => 'gateway', 'amount' => round($total, 2)]]);
                $tendered = round($total, 2);
                $dueRemainder = 0.0;
                $paymentMode = 'gateway';
            } else {
                // Multi-tender: sum whatever cash/bkash/nagad was actually
                // received; whatever's left of $total becomes the attached
                // customer's due. Never trust a client-computed remainder —
                // it's always $total minus the server-validated tender sum.
                $payments = collect($forcedPayments ?? $data['payments'] ?? [])
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

                // payment_mode stays a quick-glance single value for backward-
                // compatible display/filtering: the specific method when there's
                // exactly one, 'credit' when it's entirely on due, 'split'
                // whenever more than one tender method is involved or a tender
                // is combined with a due remainder.
                $distinctMethods = $payments->pluck('method')->unique()->count();
                $paymentMode = match (true) {
                    $total <= 0.01 => 'cash', // fully-discounted/free sale — nothing tendered or owed
                    $tendered <= 0.01 => 'credit',
                    $dueRemainder > 0.01 || $distinctMethods > 1 => 'split',
                    default => $payments->first()['method'],
                };
            }

            // invoice number — locked shop row above guarantees no collision
            $shop->invoice_counter += 1;
            $invoiceNo = 'INV-'.$shop->invoice_counter;

            $sale = Sale::create([
                'shop_id' => $shopId,
                'customer_id' => $customer?->id,
                'user_id' => $userId,
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
                'sale_type' => $isWholesale ? 'wholesale' : 'retail',
                'prescription_note' => $data['prescription_note'] ?? null,
                'coupon_code' => $couponResult['promotion']?->code,
                'points_earned' => $pointsEarned ?: null,
                'points_redeemed' => $redeemPoints ?: null,
            ]);

            if ($quotation) {
                $quotation->update(['status' => 'converted', 'sale_id' => $sale->id]);
            }

            if ($couponResult['promotion']) {
                Promotion::whereKey($couponResult['promotion']->id)->increment('used_count');
            }
            if ($bogoResult['triggeredIds']) {
                Promotion::whereIn('id', $bogoResult['triggeredIds'])->increment('used_count');
            }

            // $batchTrackingOn was already computed above (needed earlier,
            // for the sellableQty pre-check) — not repeated here
            $serialTrackingOn = $shop->hasFeature('serial_tracking');

            foreach ($lines as $line) {
                $batchAllocations = null;
                $serial = null;

                if ($line['variant']) {
                    // products.stock is maintained as the live sum of its
                    // variants' stock — both move by the same amount so
                    // that invariant holds for every other report/alert
                    // that reads products.stock without knowing variants exist.
                    ProductVariant::whereKey($line['variant']->id)->decrement('stock', $line['qty']);
                    $line['product']->decrement('stock', $line['qty']);
                } else {
                    $line['product']->decrement('stock', $line['base_qty']);

                    // best-effort ingredient consumption for a recipe-linked
                    // product (e.g. a restaurant dish) — see IngredientConsumption
                    \App\Support\IngredientConsumption::apply($line['product'], $line['base_qty']);

                    // FEFO (soonest-expiry-first) — best-effort bookkeeping
                    // only, never blocks or alters the sale itself; see
                    // BatchStock for why it can't fully cover every sale.
                    // The returned allocation is snapshotted onto the
                    // sale_item so a later void can restore stock to the
                    // exact batch it came from. (Variant lines skip this —
                    // batches aren't variant-aware. Weighed lines skip it
                    // too — batches are a whole-unit pharmacy concept, a
                    // fractional-kg line has no batch of its own to deduct from.)
                    $batchAllocations = ($batchTrackingOn && ! $line['product']->sold_by_weight)
                        ? \App\Support\BatchStock::deduct($line['product'], (int) $line['base_qty'])
                        : null;

                    // Same best-effort spirit as batches — an IMEI typed at
                    // checkout marks that exact unit sold (for warranty
                    // lookup later); a line with no IMEI, or one that
                    // doesn't match any in-stock unit, still sells normally.
                    // (Also meaningless for a weighed line — no single unit
                    // to name a serial for.)
                    if ($serialTrackingOn && $line['imei'] && ! $line['product']->sold_by_weight) {
                        $serial = \App\Support\SerialStock::sell($line['product'], $line['imei']);
                    }
                }

                SaleItem::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'product_serial_id' => $serial?->id,
                    'product_name' => $line['product']->name,
                    'unit_label' => $line['unit_label'],
                    'variant_label' => $line['variant_label'],
                    'unit_factor' => $line['unit_factor'],
                    'qty' => $line['qty'],
                    'price' => $line['price'],
                    'discount' => $line['discount'],
                    'cost' => $line['cost'],
                    'batch_allocations' => $batchAllocations,
                ]);
            }

            foreach ($payments as $payment) {
                SalePayment::create([
                    'shop_id' => $shopId,
                    'sale_id' => $sale->id,
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                ]);

                if ($payment['method'] === 'cash') {
                    $shop->cash_balance += $payment['amount'];
                } else { // bkash / nagad
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
                if ($redeemPoints > 0) {
                    $customer->decrement('loyalty_points', $redeemPoints);
                }
                if ($pointsEarned > 0) {
                    $customer->increment('loyalty_points', $pointsEarned);
                }
            }

            return $sale->load('items', 'customer', 'payments');
        });
    }

    public function barcode(string $barcode)
    {
        $product = Product::with(['productUnits.unit'])->where('barcode', $barcode)->first();

        if (! $product) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true, 'product' => $product]);
    }
}
