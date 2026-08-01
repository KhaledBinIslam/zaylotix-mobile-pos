<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
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

            // same invariant-safety reasoning as DamageController/ProductController
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

            // This form doesn't ask the cashier to pick which invoice the
            // item came from (most shops here don't keep receipts), so the
            // only guard against a fabricated/duplicated return is a
            // lifetime ceiling: never let returned qty exceed what's ever
            // actually been sold for this product, minus what's already
            // been returned against it.
            // whereHas('sale', ...) — not a plain where('product_id', ...) —
            // so this only counts items whose parent Sale still exists under
            // Sale's default "not voided" scope. A voided sale already got
            // its stock given back by SaleReversal; counting its line items
            // here too would let the same units be "returned" a second time
            // for a second stock credit and cash refund.
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

            // batch-wise: if the cashier names which batch the returned
            // units are rejoining (matches its printed expiry), that
            // batch's own qty gets the units back too — optional, purely
            // supplementary to products.stock, same as damage
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
            ]);

            // Deliberately allowed to go negative rather than silently
            // clamped to 0 — a refund larger than cash-on-hand means the
            // shop is genuinely short (or paid it from outside the drawer),
            // and hiding that behind a floor of 0 would make the balance
            // sheet quietly stop matching reality with no way to notice.
            Shop::whereKey(Tenancy::id())->decrement('cash_balance', $data['refund']);
        });

        return back()->with('success', 'Return recorded.');
    }
}
