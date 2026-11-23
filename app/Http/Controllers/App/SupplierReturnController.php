<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierReturnController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('shop_id', Tenancy::id())],
            'supplier' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'settlement_method' => ['required', 'in:cash,bank,payable'],
            // set by the frontend only after the owner has explicitly
            // confirmed the "stock will go negative" dialog
            'force' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        // Confirmed bug (see docs/FIX_PLAN.md Phase 4.7), same root cause as
        // the purchase form's: a free-text supplier name never resolved to
        // a real Supplier row, so settling a return against payable was
        // blocked outright unless the supplier was already in the list.
        // Resolving it up front means a brand-new supplier typed by name can
        // now settle against payable too, exactly like one already on file.
        if (empty($data['supplier_id']) && ! empty($data['supplier'])) {
            $data['supplier_id'] = Supplier::firstOrCreate(['name' => $data['supplier']])->id;
        }

        if ($data['settlement_method'] === 'payable' && empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'settlement_method' => 'বকেয়া থেকে বাদ দিতে হলে সাপ্লায়ারের নাম লিখুন বা তালিকা থেকে বেছে নিন।',
            ]);
        }

        DB::transaction(function () use ($data) {
            $totalAmount = 0.0;
            $sourceLinesOn = Tenancy::shop()?->hasFeature('source_lines');

            foreach (array_values($data['lines']) as $i => $line) {
                $product = Product::withCount('variants')->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();

                // same invariant-safety rules as DamageController: a variant
                // product's own `stock` is the live sum of its variants', so
                // the specific size/color must be named — and a named
                // variant must really belong to this product
                $variant = null;
                if ($product->variants_count > 0 && empty($line['product_variant_id'])) {
                    throw ValidationException::withMessages([
                        "lines.{$i}.product_variant_id" => "{$product->name} ভ্যারিয়েন্ট পণ্য — কোন সাইজ/রং ফেরত যাচ্ছে সেটি বেছে নিন।",
                    ]);
                }
                if (! empty($line['product_variant_id'])) {
                    if ($product->variants_count === 0) {
                        throw ValidationException::withMessages([
                            "lines.{$i}.product_variant_id" => "{$product->name}-এর কোনো ভ্যারিয়েন্ট নেই।",
                        ]);
                    }
                    $variant = ProductVariant::whereKey($line['product_variant_id'])->lockForUpdate()->first();
                    if (! $variant || $variant->product_id !== $product->id) {
                        throw ValidationException::withMessages([
                            "lines.{$i}.product_variant_id" => 'এই ভ্যারিয়েন্টটি সঠিক নয়।',
                        ]);
                    }
                }

                // unlike a customer return (bounded by what was ever sold), a
                // supplier return is bounded by what's physically on the
                // shelf — going below zero needs the owner's explicit
                // confirmation (force), it is never silently allowed
                $available = $variant ? (float) $variant->stock : (float) $product->stock;
                if ($line['qty'] > $available && empty($data['force'])) {
                    $label = $variant ? "{$product->name} ({$variant->label()})" : $product->name;
                    throw ValidationException::withMessages([
                        'force' => "{$label} — বর্তমান স্টক {$available}, ফেরত দিলে স্টক মাইনাসে চলে যাবে। তবুও এগোতে চাইলে নিশ্চিত করুন।",
                    ]);
                }

                if ($variant) {
                    // products.stock is maintained as the live sum of its
                    // variants' stock — both move together, same two-call
                    // shape as PosController checkout / DamageController
                    $variant->applyStockDelta(-$line['qty']);
                    $product->applyStockDelta(-$line['qty']);
                } else {
                    $product->applyStockDelta(-$line['qty']);
                }

                // line-tracked shop: the returned units leave THIS supplier's
                // own lot first (that's whose goods went back), spilling
                // oldest-first across the rest only if theirs runs out —
                // otherwise the lines drift above the mirror forever
                if ($sourceLinesOn) {
                    $supplierIdForLines = $data['supplier_id'] ?? null;
                    $sourceLines = \App\Models\ProductSource::where('product_id', $product->id)
                        ->when($variant,
                            fn ($q) => $q->where('product_variant_id', $variant->id),
                            fn ($q) => $q->whereNull('product_variant_id'))
                        ->orderBy('id')->lockForUpdate()->get()
                        ->sortBy([
                            fn ($a, $b) => (int) ($b->supplier_id === $supplierIdForLines) <=> (int) ($a->supplier_id === $supplierIdForLines),
                            fn ($a, $b) => $a->id <=> $b->id,
                        ])->values();
                    \App\Models\ProductSource::applyFifoDelta($sourceLines, -(float) $line['qty']);
                }

                SupplierReturn::create([
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'supplier' => $data['supplier'] ?? null,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'qty' => $line['qty'],
                    'reason' => $data['reason'] ?? null,
                    'settlement_method' => $data['settlement_method'],
                    'amount' => $line['amount'],
                    'date' => now()->toDateString(),
                ]);

                $label = $variant ? "{$product->name} ({$variant->label()})" : $product->name;
                Activity::log('supplier.return', "'{$label}' সাপ্লায়ারকে ফেরত দেওয়া হয়েছে — {$line['qty']} ইউনিট, ".number_format((float) $line['amount'], 2).' টাকা।', $product, [
                    'qty' => $line['qty'], 'amount' => $line['amount'], 'product_variant_id' => $variant?->id,
                ]);

                $totalAmount += (float) $line['amount'];
            }

            if ($data['settlement_method'] === 'payable') {
                Supplier::whereKey($data['supplier_id'])->decrement('payable', $totalAmount);
            } else {
                $field = $data['settlement_method'] === 'cash' ? 'cash_balance' : 'bank_balance';
                Shop::whereKey(Tenancy::id())->increment($field, $totalAmount);
            }
        });

        return back()->with('success', 'সাপ্লায়ার রিটার্ন রেকর্ড করা হয়েছে।');
    }
}
