<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A price quote given to a customer before they buy — printable/shareable,
 * later either converted into a real sale (see PosController::checkout()'s
 * `quotation_id` handling, which owns the actual stock/money movement) or
 * left to expire/be cancelled. Deliberately scoped to plain products only —
 * no pack units or size/color variants — a quote's job is a clean, simple
 * price list, not a full cart replica.
 */
class QuotationController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Quotations/Index', [
            'quotations' => Quotation::with('items')->orderByDesc('id')->get(),
            // sold_by_weight/weight_unit included so the quote form can let a
            // weighed product take a fractional qty (e.g. 1.5 kg) while
            // every other product still requires a whole number — see
            // store()'s matching per-line check below
            'products' => Product::whereDoesntHave('variants')->orderBy('name')->get(['id', 'name', 'emoji', 'price', 'sold_by_weight', 'weight_unit']),
        ]);
    }

    public function show(Quotation $quotation)
    {
        return Inertia::render('App/Quotations/Show', [
            'quotation' => $quotation->load('items', 'customer'),
        ]);
    }

    private function quotationRules(): array
    {
        return [
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('shop_id', Tenancy::id())],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            // numeric at this layer — a weighed product's line may be a
            // fractional kg/litre/metre qty, enforced per-line just below
            // once the product itself is known (mirrors
            // PosController::performCheckout's identical gating)
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Shared by store() and update() — resolves each line's product,
     * enforces the weighed-vs-whole-number qty rule, and returns the line
     * payloads + subtotal + any low-stock warnings together so the two
     * callers can never compute a quote's pricing differently from each
     * other (same reasoning as PosController's single performCheckout()).
     */
    private function resolveLines(array $items): array
    {
        $products = Product::whereIn('id', collect($items)->pluck('product_id'))->get()->keyBy('id');

        $subtotal = 0;
        $lines = [];
        $lowStockWarnings = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if (! $product->sold_by_weight && floor($item['qty']) != $item['qty']) {
                throw ValidationException::withMessages([
                    'items' => "{$product->name} ওজন-ভিত্তিক পণ্য না — পরিমাণ পূর্ণ সংখ্যা হতে হবে।",
                ]);
            }
            // Confirmed gap (see docs/FIX_PLAN.md Phase 6): creation never
            // checked quoted qty against current stock at all — a quote for
            // 50 of something with 5 in stock went through silently. A
            // quote isn't a stock reservation (the shop may restock before
            // anyone converts it), so this warns rather than blocks —
            // conversion itself is still the one place that actually
            // re-validates live stock and hard-blocks if it's genuinely
            // insufficient at that later moment.
            if ((float) $item['qty'] > (float) $product->stock) {
                $lowStockWarnings[] = "{$product->name} (চাহিদা {$item['qty']}, আছে {$product->stock})";
            }
            $rawLineTotal = (float) $item['price'] * $item['qty'];
            $lineDiscount = min((float) ($item['discount'] ?? 0), $rawLineTotal);
            $subtotal += $rawLineTotal - $lineDiscount;

            $lines[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'qty' => $item['qty'],
                'price' => $item['price'],
                'discount' => $lineDiscount,
            ];
        }

        return [$lines, $subtotal, $lowStockWarnings];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->quotationRules());
        $shopId = Tenancy::id();

        [$quotation, $lowStockWarnings] = DB::transaction(function () use ($data, $shopId) {
            // locking the shop row serializes concurrent quote submissions
            // so the sequential quote_no can't collide, same pattern as
            // Sale's invoice_no
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            [$lines, $subtotal, $lowStockWarnings] = $this->resolveLines($data['items']);

            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $total = $subtotal - $discount;

            $shop->quotation_counter += 1;
            $quoteNo = 'QUO-'.$shop->quotation_counter;
            $shop->save();

            $quotation = Quotation::create([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'quote_no' => $quoteNo,
                'date' => now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'status' => 'open',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                QuotationItem::create($line + ['quotation_id' => $quotation->id]);
            }

            return [$quotation, $lowStockWarnings];
        });

        $message = "{$quotation->quote_no} তৈরি হয়েছে।";
        if (! empty($lowStockWarnings)) {
            $message .= ' ⚠️ পর্যাপ্ত স্টক নেই: '.implode(', ', $lowStockWarnings).' — বিক্রির আগে স্টক নিশ্চিত করুন।';
        }

        return back()->with('success', $message);
    }

    /**
     * Missing feature (see docs/FIX_PLAN.md Phase 6): "cancel and create a
     * new one" was the only way to fix a mistake on an open quote. Only
     * ever touches an 'open' quotation — a converted/cancelled one keeps
     * its historical record exactly as it was; the quote_no/date never
     * change, only the content and the derived totals.
     */
    public function update(Request $request, Quotation $quotation)
    {
        if ($quotation->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => 'শুধু খোলা কোটেশন সম্পাদনা করা যাবে।',
            ]);
        }

        $data = $request->validate($this->quotationRules());

        $lowStockWarnings = DB::transaction(function () use ($data, $quotation) {
            [$lines, $subtotal, $lowStockWarnings] = $this->resolveLines($data['items']);

            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $total = $subtotal - $discount;

            $quotation->update([
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            // replace every line wholesale rather than diffing — a
            // quotation's items have no other row anywhere referencing
            // their own id (unlike a Sale's items, never read by anything
            // once billed), so there's nothing to preserve by patching
            // in place
            $quotation->items()->delete();
            foreach ($lines as $line) {
                QuotationItem::create($line + ['quotation_id' => $quotation->id]);
            }

            return $lowStockWarnings;
        });

        $message = "{$quotation->quote_no} আপডেট করা হয়েছে।";
        if (! empty($lowStockWarnings)) {
            $message .= ' ⚠️ পর্যাপ্ত স্টক নেই: '.implode(', ', $lowStockWarnings).' — বিক্রির আগে স্টক নিশ্চিত করুন।';
        }

        return back()->with('success', $message);
    }

    public function cancel(Quotation $quotation)
    {
        if ($quotation->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => 'শুধু খোলা কোটেশন বাতিল করা যাবে।',
            ]);
        }

        $quotation->update(['status' => 'cancelled']);

        return back()->with('success', 'কোটেশন বাতিল করা হয়েছে।');
    }
}
