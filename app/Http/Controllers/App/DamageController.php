<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DamageController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer'],
            'product_batch_id' => ['nullable', 'exists:product_batches,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data) {
            $product = Product::withCount('variants')->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();

            // a variant product's `stock` is a live-maintained sum of its
            // variants' stock (see ProductController) — writing to it
            // directly here would desync that sum from its parts with
            // nothing to ever resync it, same reasoning as stockIn/update.
            // A specific variant is fine — that's exactly what the matching
            // branch below does, moving both the variant's own stock and
            // the product total together, same as StockTransferController.
            if ($product->variants_count > 0 && empty($data['product_variant_id'])) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name}-এর সাইজ/রং আছে — একটা নির্দিষ্ট ভ্যারিয়েন্ট বেছে নষ্ট লিখুন।",
                ]);
            }
            if ($product->variants_count === 0 && ! empty($data['product_variant_id'])) {
                throw ValidationException::withMessages([
                    'product_variant_id' => "{$product->name}-এর কোনো ভ্যারিয়েন্ট নেই।",
                ]);
            }

            // fractional damage (e.g. 0.5 kg of rice spilled) only makes
            // sense for a weighed product — a piece-counted product's qty
            // must stay a whole number
            if (! $product->sold_by_weight && floor($data['qty']) != $data['qty']) {
                throw ValidationException::withMessages([
                    'qty' => "{$product->name}-এর পরিমাণ পূর্ণ সংখ্যা হতে হবে।",
                ]);
            }

            if (! empty($data['product_variant_id'])) {
                $variant = ProductVariant::whereKey($data['product_variant_id'])->lockForUpdate()->first();
                if (! $variant || $variant->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        'product_variant_id' => 'এই ভ্যারিয়েন্টটি সঠিক নয়।',
                    ]);
                }
                if ($variant->stock < $data['qty']) {
                    throw ValidationException::withMessages([
                        'qty' => "পর্যাপ্ত স্টক নেই: {$product->name} ({$variant->label()}) — আছে মাত্র {$variant->stock}টি।",
                    ]);
                }

                $loss = ((float) ($variant->cost ?? $product->cost)) * (float) $data['qty'];
                $variant->applyStockDelta(-$data['qty']);
                $product->applyStockDelta(-$data['qty']);

                Damage::create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'qty' => $data['qty'],
                    'reason' => $data['reason'] ?? null,
                    'loss' => $loss,
                    'date' => now()->toDateString(),
                ]);

                return;
            }

            // A ValidationException (not abort()) is required here so this
            // shows inline under the qty field via the Inertia form's
            // `errors` bag — abort() throws a plain HttpException, which an
            // Inertia form POST can't turn into a field error; the user
            // would just see the page seem to do nothing.
            if ($product->stock < $data['qty']) {
                throw ValidationException::withMessages([
                    'qty' => "পর্যাপ্ত স্টক নেই: {$product->name}-এ আছে মাত্র {$product->stock}টি।",
                ]);
            }

            // batch-wise: if the cashier named which physical batch these
            // units came from, that batch's own qty moves too, so future
            // FEFO deduction/expiry alerts stay accurate against reality —
            // optional and independent of products.stock, which always
            // moves regardless (same "supplementary layer" pattern as sales)
            $batch = null;
            if (! empty($data['product_batch_id'])) {
                $batch = ProductBatch::whereKey($data['product_batch_id'])->lockForUpdate()->first();
                if (! $batch || $batch->product_id !== $product->id) {
                    throw ValidationException::withMessages([
                        'product_batch_id' => 'এই ব্যাচটি এই পণ্যের জন্য সঠিক নয়।',
                    ]);
                }
                if ($batch->qty < $data['qty']) {
                    throw ValidationException::withMessages([
                        'product_batch_id' => "এই ব্যাচে মাত্র {$batch->qty} ইউনিট আছে।",
                    ]);
                }
            }

            $loss = $product->cost * $data['qty'];
            $product->applyStockDelta(-$data['qty']);
            $batch?->decrement('qty', $data['qty']);

            Damage::create([
                'product_id' => $product->id,
                'product_batch_id' => $batch?->id,
                'qty' => $data['qty'],
                'reason' => $data['reason'] ?? null,
                'loss' => $loss,
                'date' => now()->toDateString(),
            ]);
        });

        return back()->with('success', 'Damage recorded.');
    }
}
