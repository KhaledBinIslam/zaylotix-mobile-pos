<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A variant (size/color) has its own independent stock — unlike product_units
 * (a pack-size conversion of one shared stock pool). products.stock is kept
 * as the live sum of all its variants' stock, so every store/stockIn/destroy
 * here moves product.stock by the exact same delta it moves the variant by —
 * that invariant is what lets every existing report/alert/valuation query
 * keep reading products.stock unchanged, whether a product has variants or not.
 */
class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'stock' => ['required', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (empty($data['size']) && empty($data['color'])) {
            return back()->withErrors(['size' => 'সাইজ অথবা রং অন্তত একটা দিন।']);
        }

        // a weighed product's stock is a plain decimal quantity in kg/litre
        // — variant stock is always a whole-unit integer (see the `stock`
        // rule above), so the two models can't coexist on one product
        if ($product->sold_by_weight) {
            return back()->withErrors(['size' => "{$product->name} ওজন/লিটার হিসেবে বিক্রি হয় — এতে ভ্যারিয়েন্ট যোগ করা যাবে না।"]);
        }

        $duplicate = ProductVariant::where('product_id', $product->id)
            ->where('size', $data['size'] ?? null)
            ->where('color', $data['color'] ?? null)
            ->exists();
        if ($duplicate) {
            return back()->withErrors(['size' => 'এই সাইজ/রং-এর ভ্যারিয়েন্ট ইতিমধ্যে আছে।']);
        }

        DB::transaction(function () use ($product, $data) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'size' => $data['size'] ?? null,
                'color' => $data['color'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'stock' => $data['stock'],
                'price' => $data['price'] ?? null,
                'cost' => $data['cost'] ?? null,
            ]);

            Product::whereKey($product->id)->increment('stock', $data['stock']);

            Activity::log('product.variant.create', "'{$product->name}'-এ নতুন ভ্যারিয়েন্ট ({$variant->label()}) যোগ করা হয়েছে।", $variant);
        });

        return back()->with('success', 'ভ্যারিয়েন্ট যোগ হয়েছে।');
    }

    public function update(Request $request, ProductVariant $productVariant)
    {
        $this->authorize('update', $productVariant->product);

        $data = $request->validate([
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (empty($data['size']) && empty($data['color'])) {
            return back()->withErrors(['size' => 'সাইজ অথবা রং অন্তত একটা দিন।']);
        }

        $productVariant->update($data);

        return back()->with('success', 'ভ্যারিয়েন্ট আপডেট হয়েছে।');
    }

    /** Adds received qty to this specific variant — mirrors ProductController::stockIn but per-variant. */
    public function stockIn(Request $request, ProductVariant $productVariant)
    {
        $this->authorize('update', $productVariant->product);

        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($productVariant, $data) {
            // null if a concurrent destroy() already soft-deleted this
            // variant while this request was waiting on the row lock — a
            // 422 here, not a fatal error calling a method on null
            $locked = ProductVariant::whereKey($productVariant->id)->lockForUpdate()->first();
            if (! $locked) {
                abort(422, 'এই ভ্যারিয়েন্টটি ইতিমধ্যে মুছে ফেলা হয়েছে।');
            }

            $locked->update([
                'stock' => $locked->stock + $data['qty'],
                'cost' => $data['cost'] ?? $locked->cost,
            ]);

            Product::whereKey($locked->product_id)->increment('stock', $data['qty']);

            Activity::log('product.variant.stockIn', "ভ্যারিয়েন্ট '{$locked->label()}'-এ {$data['qty']} স্টক যোগ করা হয়েছে।", $locked);
        });

        return back()->with('success', 'স্টক আপডেট হয়েছে।');
    }

    public function destroy(ProductVariant $productVariant)
    {
        $this->authorize('delete', $productVariant->product);

        DB::transaction(function () use ($productVariant) {
            // null if a concurrent destroy() already won this race — just a
            // no-op then, the variant's already gone and stock already
            // adjusted, not an error
            $locked = ProductVariant::whereKey($productVariant->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            // keep products.stock's "sum of variants" invariant correct —
            // this variant's remaining stock leaves with it
            Product::whereKey($locked->product_id)->decrement('stock', $locked->stock);

            $locked->delete(); // soft delete — keeps historical sale_items intact
        });

        return back()->with('success', 'ভ্যারিয়েন্ট মুছে ফেলা হয়েছে।');
    }
}
