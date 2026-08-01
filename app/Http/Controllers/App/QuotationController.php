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
            'products' => Product::whereDoesntHave('variants')->orderBy('name')->get(['id', 'name', 'emoji', 'price']),
        ]);
    }

    public function show(Quotation $quotation)
    {
        return Inertia::render('App/Quotations/Show', [
            'quotation' => $quotation->load('items', 'customer'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('shop_id', Tenancy::id())],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $shopId = Tenancy::id();

        $quotation = DB::transaction(function () use ($data, $shopId) {
            // locking the shop row serializes concurrent quote submissions
            // so the sequential quote_no can't collide, same pattern as
            // Sale's invoice_no
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();

            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

            $subtotal = 0;
            $lines = [];
            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);
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

            return $quotation;
        });

        return back()->with('success', "{$quotation->quote_no} তৈরি হয়েছে।");
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
