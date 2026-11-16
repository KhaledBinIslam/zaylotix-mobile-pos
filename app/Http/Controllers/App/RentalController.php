<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\RentalReturn;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Sell some products, rent others (Product::rental_mode/rent_price). Renting
 * never decrements stock — the item is still shop property, just physically
 * with the customer — so "available/rented/damaged/lost" per product/variant
 * is always computed from rental_items/rental_returns, never a stored
 * counter (see Product::rentedQty() and friends). Every money movement goes
 * through the SAME Customer.due + Payment ledger PosController/
 * ReturnController/PaymentController already use — there is no second,
 * parallel rental-due number anywhere, so a rental's own due always
 * reconciles exactly with the customer's real due ledger.
 */
class RentalController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'active');
        $today = now()->toDateString();

        $rentals = Rental::with(['customer:id,name,phone', 'items.product:id,name,emoji'])
            ->when($tab === 'active', fn ($q) => $q->whereIn('status', ['active', 'partially_returned']))
            ->when($tab === 'overdue', fn ($q) => $q->whereIn('status', ['active', 'partially_returned'])->whereDate('expected_return_date', '<', $today))
            ->when($tab === 'today', fn ($q) => $q->whereDate('rental_date', $today))
            ->when($tab === 'upcoming', fn ($q) => $q->whereIn('status', ['active', 'partially_returned'])->whereDate('expected_return_date', '>=', $today)->whereDate('expected_return_date', '<=', now()->addDays(3)->toDateString()))
            ->when($tab === 'returned', fn ($q) => $q->where('status', 'returned'))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $rentals->getCollection()->transform(fn (Rental $r) => $this->presentRental($r));

        return Inertia::render('App/Rentals/Index', [
            'rentals' => $rentals,
            'tab' => $tab,
            'products' => $this->rentableProducts(),
            'summary' => [
                'todayCount' => Rental::whereDate('rental_date', $today)->count(),
                'todayAmount' => (float) Rental::whereDate('rental_date', $today)->sum('total_rent_amount'),
                'overdueCount' => Rental::whereIn('status', ['active', 'partially_returned'])->whereDate('expected_return_date', '<', $today)->count(),
                'activeCount' => Rental::whereIn('status', ['active', 'partially_returned'])->count(),
                'totalIncome' => (float) Payment::whereNotNull('rental_id')->sum('amount'),
                'damagedCount' => (float) Damage::whereNotNull('rental_id')->sum('qty'),
            ],
        ]);
    }

    private function rentableProducts()
    {
        return Product::with(['variants' => fn ($q) => $q->orderBy('size')->orderBy('color')])
            ->whereIn('rental_mode', [Product::RENTAL_MODE_RENT_ONLY, Product::RENTAL_MODE_BOTH])
            ->get(['id', 'name', 'rent_price', 'stock', 'rental_mode'])
            ->map(function (Product $p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'rent_price' => (float) $p->rent_price,
                    'available' => $p->availableForRent(),
                    'variants' => $p->variants->map(fn (ProductVariant $v) => [
                        'id' => $v->id, 'label' => trim(($v->size ?? '').' '.($v->color ?? '')),
                        'available' => $v->availableForRent(),
                    ])->values(),
                ];
            });
    }

    private function presentRental(Rental $rental): array
    {
        return [
            'id' => $rental->id,
            'invoice_no' => $rental->invoice_no,
            'customer' => $rental->customer ? ['id' => $rental->customer->id, 'name' => $rental->customer->name, 'phone' => $rental->customer->phone] : null,
            'rental_date' => $rental->rental_date->toDateString(),
            'expected_return_date' => $rental->expected_return_date->toDateString(),
            'actual_return_date' => $rental->actual_return_date?->toDateString(),
            'status' => $rental->status,
            'is_overdue' => $rental->isOverdue(),
            'total_rent_amount' => (float) $rental->total_rent_amount,
            'total_paid' => $rental->totalPaid(),
            'remaining_due' => $rental->remainingDue(),
            'items' => $rental->items->map(fn (RentalItem $i) => [
                'id' => $i->id, 'product_name' => $i->product?->name, 'qty' => (float) $i->qty,
                'returned_qty' => (float) $i->returned_qty, 'outstanding_qty' => $i->outstandingQty(), 'rent_price' => (float) $i->rent_price,
            ])->values(),
        ];
    }

    public function lookup(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '') {
            return response()->json(['rentals' => []]);
        }

        $rentals = Rental::with(['customer', 'items.product'])
            ->where(fn ($query) => $query->where('invoice_no', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('phone', $q)))
            ->latest('id')->limit(10)->get();

        return response()->json(['rentals' => $rentals->map(fn (Rental $r) => $this->presentRental($r))->values()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'rental_date' => ['required', 'date'],
            'expected_return_date' => ['required', 'date', 'after_or_equal:rental_date'],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.rent_price' => ['required', 'numeric', 'min:0'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_method' => ['nullable', 'in:cash,bkash,nagad,bank'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        // Idempotency: same pattern as Sale.client_uuid — a resubmitted
        // "নতুন রেন্টাল" tap (independent of whether an advance was given)
        // must return the original rental, not create a second one.
        if (! empty($data['client_uuid'])) {
            $existing = Rental::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return response()->json(['rental' => $this->presentRental($existing->load(['customer', 'items.product']))]);
            }
        }

        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();

        $rental = DB::transaction(function () use ($data, $shopId, $userId) {
            $lockedShop = Shop::whereKey($shopId)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($data['customer_id'])->lockForUpdate()->first();
            if (! $customer) {
                abort(422, 'কাস্টমার খুঁজে পাওয়া যায়নি।');
            }

            $productIds = collect($data['items'])->pluck('product_id')->unique();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique();
            $variants = $variantIds->isEmpty() ? collect() : ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');

            $total = 0.0;
            $lines = [];
            foreach ($data['items'] as $row) {
                $product = $products->get($row['product_id']);
                if (! $product || ! $product->canBeRented()) {
                    abort(422, "এই পণ্যটি ভাড়া দেওয়ার জন্য configured না।");
                }
                $qty = (float) $row['qty'];
                $variant = ! empty($row['product_variant_id']) ? $variants->get($row['product_variant_id']) : null;
                $availableNow = $variant ? $variant->availableForRent() : $product->availableForRent();
                if ($qty > $availableNow + 0.0001) {
                    abort(422, "{$product->name}: পর্যাপ্ত স্টক নেই (ভাড়ায় আছে, তাই এখন সর্বোচ্চ {$availableNow}টি পাওয়া যাবে)।");
                }
                $lines[] = ['product' => $product, 'variant' => $variant, 'qty' => $qty, 'rent_price' => (float) $row['rent_price']];
                $total += $qty * (float) $row['rent_price'];
            }

            $lockedShop->rental_invoice_counter += 1;
            $invoiceNo = 'RNT-'.$lockedShop->rental_invoice_counter;
            $lockedShop->save();

            $rental = Rental::create([
                'shop_id' => $shopId,
                'customer_id' => $customer->id,
                'invoice_no' => $invoiceNo,
                'rental_date' => $data['rental_date'],
                'expected_return_date' => $data['expected_return_date'],
                'status' => 'active',
                'total_rent_amount' => round($total, 2),
                'security_deposit' => $data['security_deposit'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $userId,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            foreach ($lines as $line) {
                RentalItem::create([
                    'shop_id' => $shopId,
                    'rental_id' => $rental->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'qty' => $line['qty'],
                    'rent_price' => $line['rent_price'],
                    'status' => 'out',
                ]);
            }

            // The whole rental amount becomes due immediately (same shape
            // as a credit POS sale), THEN the advance (if any) is collected
            // against it via the normal Payment ledger — so customer.due
            // and this rental's own remainingDue() always agree exactly.
            $dueBeforeCharge = (float) $customer->due;
            $customer->due = $dueBeforeCharge + (float) $rental->total_rent_amount;

            $advance = round((float) ($data['advance_amount'] ?? 0), 2);
            if ($advance > 0) {
                if ($advance - (float) $customer->due > 0.01) {
                    throw ValidationException::withMessages(['advance_amount' => 'অগ্রিম মোট ভাড়ার চেয়ে বেশি হতে পারবে না।']);
                }
                $dueAfterCharge = (float) $customer->due;
                $customer->due = $dueAfterCharge - $advance;
                Payment::create([
                    'shop_id' => $shopId,
                    'customer_id' => $customer->id,
                    'rental_id' => $rental->id,
                    'user_id' => $userId,
                    'amount' => $advance,
                    'due_before' => $dueAfterCharge,
                    'due_after' => $customer->due,
                    'method' => $data['advance_method'] ?? 'cash',
                    'date' => now()->toDateString(),
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);
                if (($data['advance_method'] ?? 'cash') === 'cash') {
                    $lockedShop->cash_balance = (float) $lockedShop->cash_balance + $advance;
                } else {
                    $lockedShop->bank_balance = (float) $lockedShop->bank_balance + $advance;
                }
                $lockedShop->save();
            }

            $customer->save();

            Activity::log('rental.create', "{$customer->name}-এর জন্য রেন্টাল ইনভয়েস '{$invoiceNo}' তৈরি হয়েছে, মোট ৳".round($total, 2), $rental, ['total' => round($total, 2), 'advance' => $advance]);

            return $rental;
        });

        return response()->json(['rental' => $this->presentRental($rental->load(['customer', 'items.product']))]);
    }

    public function show(Rental $rental)
    {
        $rental->load(['customer', 'items.product', 'items.variant', 'returns.item', 'payments' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id')]);

        return Inertia::render('App/Rentals/Show', [
            'rental' => $this->presentRental($rental),
            'rawItems' => $rental->items->map(fn (RentalItem $i) => [
                'id' => $i->id, 'product_name' => $i->product?->name,
                'variant_label' => $i->variant ? trim(($i->variant->size ?? '').' '.($i->variant->color ?? '')) : null,
                'qty' => (float) $i->qty, 'returned_qty' => (float) $i->returned_qty, 'outstanding_qty' => $i->outstandingQty(),
                'rent_price' => (float) $i->rent_price, 'status' => $i->status,
            ])->values(),
            'returns' => $rental->returns->map(fn (RentalReturn $r) => [
                'id' => $r->id, 'rental_item_id' => $r->rental_item_id, 'qty' => (float) $r->qty, 'condition' => $r->condition,
                'late_fee' => (float) $r->late_fee, 'damage_charge' => (float) $r->damage_charge, 'date' => $r->date->toDateString(),
            ])->values(),
            'payments' => $rental->payments->map(fn (Payment $p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->method, 'date' => $p->date->toDateString(),
            ])->values(),
        ]);
    }

    /**
     * One locked transaction: lines[] = [{rental_item_id, qty, condition, late_fee, damage_charge}].
     * resalable -> nothing to restock (renting never decremented stock in the
     * first place). damaged/lost -> decrement stock now (the opposite of
     * ReturnController's damaged case, where stock had already left at sale
     * time) and write a Damage row. Any late_fee/damage_charge increases
     * customer.due; an optional payment collected now reduces it, through
     * the same shared ledger as everything else.
     */
    public function processReturn(Request $request, Rental $rental)
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.rental_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.condition' => ['required', Rule::in(['resalable', 'damaged', 'lost'])],
            'lines.*.late_fee' => ['nullable', 'numeric', 'min:0'],
            'lines.*.damage_charge' => ['nullable', 'numeric', 'min:0'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,bkash,nagad,bank'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        // Idempotency: a resubmitted return may carry no payment at all
        // (just marking items returned/damaged), so RentalReturn's own
        // client_uuid is checked, not just Payment's — this is an
        // existence check, not a unique constraint, since one request
        // writes one RentalReturn row per line, all sharing this same key.
        if (! empty($data['client_uuid']) && RentalReturn::where('client_uuid', $data['client_uuid'])->exists()) {
            return response()->json(['rental' => $this->presentRental($rental->fresh(['customer', 'items.product']))]);
        }

        $shopId = Tenancy::id();
        $userId = Auth::guard('web')->id() ?? Auth::guard('sanctum')->id();

        $result = DB::transaction(function () use ($data, $rental, $shopId, $userId) {
            $lockedShop = Shop::whereKey($shopId)->lockForUpdate()->firstOrFail();
            $lockedRental = Rental::whereKey($rental->id)->lockForUpdate()->first();
            $customer = Customer::whereKey($lockedRental->customer_id)->lockForUpdate()->first();

            $items = RentalItem::whereIn('id', collect($data['lines'])->pluck('rental_item_id'))
                ->where('rental_id', $lockedRental->id)->lockForUpdate()->get()->keyBy('id');

            $totalExtraCharge = 0.0;
            foreach ($data['lines'] as $line) {
                $item = $items->get($line['rental_item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['lines' => 'এই রেন্টালের একটি আইটেম খুঁজে পাওয়া যায়নি।']);
                }
                $qty = (float) $line['qty'];
                if ($qty > $item->outstandingQty() + 0.0001) {
                    throw ValidationException::withMessages(['lines' => "{$item->product->name}: সর্বোচ্চ {$item->outstandingQty()} ইউনিট ফেরত নেওয়া যাবে।"]);
                }

                $lateFee = round((float) ($line['late_fee'] ?? 0), 2);
                $damageCharge = round((float) ($line['damage_charge'] ?? 0), 2);
                $totalExtraCharge += $lateFee + $damageCharge;

                RentalReturn::create([
                    'shop_id' => $shopId,
                    'rental_id' => $lockedRental->id,
                    'rental_item_id' => $item->id,
                    'qty' => $qty,
                    'condition' => $line['condition'],
                    'late_fee' => $lateFee,
                    'damage_charge' => $damageCharge,
                    'date' => now()->toDateString(),
                    'user_id' => $userId,
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);

                if ($line['condition'] !== 'resalable') {
                    // Opposite of a sale's return: the stock figure was
                    // NEVER decremented while this was out on rent, so a
                    // genuine loss/damage must decrement it now, or the
                    // shop's own books would still show it as sellable/
                    // rentable stock it no longer actually has.
                    if ($item->product_variant_id) {
                        ProductVariant::whereKey($item->product_variant_id)->lockForUpdate()->first()?->applyStockDelta(-$qty);
                    }
                    $item->product?->applyStockDelta(-$qty);

                    Damage::create([
                        'shop_id' => $shopId,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'rental_id' => $lockedRental->id,
                        'rental_item_id' => $item->id,
                        'qty' => $qty,
                        'reason' => $line['condition'] === 'lost' ? 'রেন্টাল — পণ্য ফেরত আসেনি' : 'রেন্টাল — ফেরত আসা পণ্য ক্ষতিগ্রস্ত',
                        'loss' => $damageCharge > 0 ? $damageCharge : (float) $item->product?->cost * $qty,
                        'date' => now()->toDateString(),
                    ]);
                }

                $item->increment('returned_qty', $qty);
                if ($item->outstandingQty() <= 0.0001) {
                    $item->update(['status' => 'returned']);
                }
            }

            if ($totalExtraCharge > 0) {
                $customer->due = (float) $customer->due + $totalExtraCharge;
            }

            $paymentAmount = round((float) ($data['payment_amount'] ?? 0), 2);
            if ($paymentAmount > 0) {
                if ($paymentAmount - (float) $customer->due > 0.01) {
                    throw ValidationException::withMessages(['payment_amount' => 'পরিশোধের পরিমাণ মোট বাকির চেয়ে বেশি হতে পারবে না।']);
                }
                $dueBefore = (float) $customer->due;
                $customer->due = $dueBefore - $paymentAmount;
                Payment::create([
                    'shop_id' => $shopId,
                    'customer_id' => $customer->id,
                    'rental_id' => $lockedRental->id,
                    'user_id' => $userId,
                    'amount' => $paymentAmount,
                    'due_before' => $dueBefore,
                    'due_after' => $customer->due,
                    'method' => $data['payment_method'] ?? 'cash',
                    'date' => now()->toDateString(),
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);
                if (($data['payment_method'] ?? 'cash') === 'cash') {
                    $lockedShop->cash_balance = (float) $lockedShop->cash_balance + $paymentAmount;
                } else {
                    $lockedShop->bank_balance = (float) $lockedShop->bank_balance + $paymentAmount;
                }
                $lockedShop->save();
            }

            $customer->save();

            $lockedRental->refresh();
            $allReturned = $lockedRental->items->every(fn (RentalItem $i) => $i->outstandingQty() <= 0.0001);
            $lockedRental->update([
                'status' => $allReturned ? 'returned' : 'partially_returned',
                'actual_return_date' => $allReturned ? now()->toDateString() : $lockedRental->actual_return_date,
            ]);

            Activity::log('rental.return', "রেন্টাল '{$lockedRental->invoice_no}'-এর আইটেম ফেরত নেওয়া হয়েছে।", $lockedRental, ['extra_charge' => $totalExtraCharge]);

            return $lockedRental;
        });

        return response()->json(['rental' => $this->presentRental($result->load(['customer', 'items.product']))]);
    }
}
