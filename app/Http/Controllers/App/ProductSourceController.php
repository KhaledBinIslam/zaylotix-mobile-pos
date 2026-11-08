<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\IntakeLedger;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Part 1 (source_lines, feature-gated) — stock-in for a supplier+brand
 * LOT ("line") rather than the product/variant as a whole. One screen:
 * pick a supplier (+ optional brand), type qty + buying price. A line
 * already on that exact supplier+brand gets the qty added (its cost
 * blends, a weighted average of ITS OWN receipts — unrelated to the
 * variant-level weighted_average_costing feature, which this feature
 * supersedes); no matching line yet creates one, copying the most
 * recent line's sell price as a starting default (editable before save).
 *
 * The variant/product MIRROR (stock/cost/price) is updated in the same
 * transaction every time — cost OVERWRITES to this line's cost (not a
 * cross-supplier blend; see the create_product_sources migration's own
 * note on why), exactly like a shop without weighted_average_costing
 * already behaves. Every other report/alert/POS path that reads the
 * mirror directly keeps working unchanged.
 */
class ProductSourceController extends Controller
{
    public function stockInProduct(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);
        abort_if($product->sold_by_weight, 422, 'ওজন/লিটার হিসেবে বিক্রি হওয়া পণ্যে একাধিক সোর্স লাইন সাপোর্ট করে না।');
        abort_if($product->variants()->exists(), 422, 'এই পণ্যের ভ্যারিয়েন্ট আছে — প্রতিটা ভ্যারিয়েন্টেই আলাদা করে লাইন যোগ করুন।');

        $data = $this->validated($request);

        DB::transaction(function () use ($product, $data) {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->first();
            $this->applyStockIn($lockedProduct, null, $data);
        });

        return back()->with('success', 'স্টক যোগ হয়েছে।');
    }

    public function stockInVariant(Request $request, ProductVariant $productVariant)
    {
        $this->authorize('update', $productVariant->product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);

        $data = $this->validated($request);

        DB::transaction(function () use ($productVariant, $data) {
            $lockedVariant = ProductVariant::whereKey($productVariant->id)->lockForUpdate()->first();
            abort_unless($lockedVariant, 422, 'এই ভ্যারিয়েন্টটি ইতিমধ্যে মুছে ফেলা হয়েছে।');
            $lockedProduct = Product::whereKey($lockedVariant->product_id)->lockForUpdate()->first();
            $this->applyStockIn($lockedProduct, $lockedVariant, $data);
        });

        return back()->with('success', 'স্টক যোগ হয়েছে।');
    }

    /**
     * Correction tool, not a stock-in — "pore kono ta stock tulte vul
     * hole ... tik [kora]". Moves qty from THIS line to another
     * supplier(+brand) line on the same product/variant: the wrong lot
     * shrinks, the right one grows (created if needed, at this lot's own
     * cost). Total stock is unchanged, so the mirror, the supplier's
     * payable and the intake ledger are all deliberately untouched —
     * nothing new arrived, it was just filed under the wrong name.
     */
    public function move(Request $request, ProductSource $productSource)
    {
        $this->authorize('update', $productSource->product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        $supplierId = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id'])?->id : null;
        $brandId = ! empty($data['brand_id']) ? Brand::find($data['brand_id'])?->id : null;

        $sameSupplier = ($supplierId ?? 0) === (int) ($productSource->supplier_id ?? 0);
        $sameBrand = ($brandId ?? 0) === (int) ($productSource->brand_id ?? 0);
        if ($sameSupplier && $sameBrand) {
            return back()->withErrors(['supplier_id' => 'এই স্টক এখনই এই সাপ্লায়ার/ব্র্যান্ডের ঘরে আছে — অন্যটা বাছুন।']);
        }

        DB::transaction(function () use ($productSource, $data, $supplierId, $brandId) {
            $from = ProductSource::whereKey($productSource->id)->lockForUpdate()->first();
            abort_unless($from, 422, 'এই লাইনটি ইতিমধ্যে মুছে ফেলা হয়েছে।');

            $qty = (int) $data['qty'];
            if ($qty > (float) $from->stock) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qty' => "এই লাইনে আছে {$from->stock} পিস — তার বেশি সরানো যাবে না।",
                ]);
            }

            $to = ProductSource::upsertLine(
                Tenancy::id(), $from->product_id, $from->product_variant_id,
                $supplierId, $brandId, $qty, (float) $from->cost, null, (float) $from->price
            );
            $from->applyStockDelta(-$qty);

            Activity::log(
                'product.source.move',
                "'{$from->product->name}' — {$qty} পিস '{$from->pickerLabel()}' থেকে '{$to->pickerLabel()}'-এর ঘরে সরানো হয়েছে (ভুল এন্ট্রি সংশোধন)।",
                $to
            );
        });

        return back()->with('success', 'স্টক সঠিক সাপ্লায়ারের ঘরে সরানো হয়েছে।');
    }

    /**
     * The other half of the correction story: stock that predates line
     * tracking (or came in through a path that didn't write lines before
     * it was fixed) sits in the mirror with NO line at all —
     * sum(lines.stock) < variant/product stock. This claims (part of)
     * that untracked remainder for a supplier(+brand) line. The mirror
     * already counts these units, so it is not touched; nothing was
     * bought today, so payable and the intake ledger aren't either.
     */
    public function claimUntrackedProduct(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);
        abort_if($product->sold_by_weight, 422, 'ওজন/লিটার হিসেবে বিক্রি হওয়া পণ্যে একাধিক সোর্স লাইন সাপোর্ট করে না।');
        abort_if($product->variants()->exists(), 422, 'এই পণ্যের ভ্যারিয়েন্ট আছে — প্রতিটা ভ্যারিয়েন্টেই আলাদা করে ঠিক করুন।');

        $data = $this->claimValidated($request);

        DB::transaction(function () use ($product, $data) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();
            abort_unless($locked, 422, 'এই পণ্যটি মুছে ফেলা হয়েছে।');
            $this->applyClaim($locked, null, $data);
        });

        return back()->with('success', 'স্টক সাপ্লায়ারের ঘরে তোলা হয়েছে।');
    }

    public function claimUntrackedVariant(Request $request, ProductVariant $productVariant)
    {
        $this->authorize('update', $productVariant->product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);

        $data = $this->claimValidated($request);

        DB::transaction(function () use ($productVariant, $data) {
            $locked = ProductVariant::whereKey($productVariant->id)->lockForUpdate()->first();
            abort_unless($locked, 422, 'এই ভ্যারিয়েন্টটি ইতিমধ্যে মুছে ফেলা হয়েছে।');
            $lockedProduct = Product::whereKey($locked->product_id)->lockForUpdate()->first();
            $this->applyClaim($lockedProduct, $locked, $data);
        });

        return back()->with('success', 'স্টক সাপ্লায়ারের ঘরে তোলা হয়েছে।');
    }

    private function claimValidated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'qty' => ['required', 'integer', 'min:1'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /** @param Product $product already locked. @param ProductVariant|null $variant already locked, or null for a plain product. */
    private function applyClaim(Product $product, ?ProductVariant $variant, array $data): void
    {
        $supplierId = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id'])?->id : null;
        $brandId = ! empty($data['brand_id']) ? Brand::find($data['brand_id'])?->id : null;
        $qty = (int) $data['qty'];

        $linesQuery = ProductSource::where('product_id', $product->id);
        $linesQuery = $variant ? $linesQuery->where('product_variant_id', $variant->id) : $linesQuery->whereNull('product_variant_id');
        $tracked = (float) $linesQuery->lockForUpdate()->get()->sum('stock');
        $mirror = (float) ($variant?->stock ?? $product->stock);
        $untracked = round($mirror - $tracked, 3);

        if ($qty > $untracked) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'qty' => "ট্র্যাক-না-হওয়া স্টক আছে {$untracked} পিস — তার বেশি ভাগ করা যাবে না।",
            ]);
        }

        $cost = isset($data['cost']) ? (float) $data['cost'] : (float) ($variant?->cost ?? $product->cost ?? 0);
        $price = isset($data['price']) ? (float) $data['price'] : null;

        $line = ProductSource::upsertLine(
            Tenancy::id(), $product->id, $variant?->id,
            $supplierId, $brandId, $qty, $cost, $price,
            (float) ($variant?->price ?? $product->price)
        );

        Activity::log(
            'product.source.claim',
            "'{$product->name}'".($variant ? " ({$variant->label()})" : '')." — ট্র্যাক-না-হওয়া {$qty} পিস '{$line->pickerLabel()}'-এর ঘরে তোলা হয়েছে।",
            $line
        );
    }

    /**
     * SEAMAN: "vul kore ... buy and sell price vul dichi eta update korar
     * option nai" — a typo at stock-in on one specific supplier's lot,
     * fixed in place. Only this line's own cost/price change; qty/stock
     * are untouched (that's stockIn/move's job). Deliberately does NOT push
     * the correction up to the variant/product mirror — that mirror may
     * have already moved on to a DIFFERENT, later lot's cost since this one
     * was filed, and silently rewinding it would be its own surprise. Any
     * sale that resolves to THIS exact lot (PosController's source-line
     * resolution) picks up the corrected price immediately either way.
     */
    public function update(Request $request, ProductSource $productSource)
    {
        $this->authorize('update', $productSource->product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);

        $data = $request->validate([
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $locked = ProductSource::whereKey($productSource->id)->lockForUpdate()->first();
        abort_unless($locked, 422, 'এই লাইনটি ইতিমধ্যে মুছে ফেলা হয়েছে।');

        $before = ['cost' => (float) $locked->cost, 'price' => (float) $locked->price];
        $locked->update([
            'cost' => (float) $data['cost'],
            'price' => $data['price'] ?? $locked->price,
        ]);

        Activity::log(
            'product.source.priceCorrected',
            "'{$locked->product->name}' — {$locked->pickerLabel()}-এর ক্রয়/বিক্রয় মূল্য সংশোধন করা হয়েছে (ক্রয় ৳{$before['cost']} → ৳{$locked->cost}, বিক্রয় ৳{$before['price']} → ৳{$locked->price})।",
            $locked
        );

        return back()->with('success', 'দাম ঠিক করা হয়েছে।');
    }

    /**
     * SEAMAN: "ekta product delete korte hole full product delete korte
     * hocche, ami caile kon supplier er delete korte cacchi" — removes just
     * ONE supplier/brand lot, not the whole product/variant. Soft-deletes
     * the line (same "never a hard delete" convention as every other
     * delete in this app — keeps history, see
     * ProductVariantController::destroy()'s identical reasoning) and, if it
     * still carries real stock, takes that stock OUT of the variant/product
     * mirror with it — the same invariant a variant's own delete already
     * protects (see this session's ZV3905 fix: a deleted row must never
     * leave the mirror counting stock nothing backs any more).
     */
    public function destroy(ProductSource $productSource)
    {
        $this->authorize('update', $productSource->product);
        abort_unless(Tenancy::shop()?->hasFeature('source_lines'), 403);

        DB::transaction(function () use ($productSource) {
            $locked = ProductSource::whereKey($productSource->id)->lockForUpdate()->first();
            abort_unless($locked, 422, 'এই লাইনটি ইতিমধ্যে মুছে ফেলা হয়েছে।');

            $stock = (float) $locked->stock;
            $label = $locked->pickerLabel();
            $productName = $locked->product->name;

            if ($stock > 0) {
                if ($locked->product_variant_id) {
                    $variant = ProductVariant::whereKey($locked->product_variant_id)->lockForUpdate()->first();
                    $variant?->applyStockDelta(-$stock);
                }
                $product = Product::whereKey($locked->product_id)->lockForUpdate()->first();
                $product?->applyStockDelta(-$stock);
            }

            Activity::log(
                'product.source.delete',
                "'{$productName}' — {$label}-এর লাইনটি মুছে ফেলা হয়েছে".($stock > 0 ? " ({$stock} পিস স্টক থেকেও বাদ গেছে)।" : '।'),
                $locked
            );

            $locked->delete();
        });

        return back()->with('success', 'সাপ্লায়ারের লাইনটি মুছে ফেলা হয়েছে।');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'qty' => ['required', 'integer', 'min:1'],
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            // Khaled live: "koto tk pay korche ... koto due ache show
            // hobe" — see SupplierPayable.
            'payment_method' => ['nullable', 'in:cash,bank,credit'],
        ]);
    }

    /** @param Product $product already locked. @param ProductVariant|null $variant already locked, or null for a plain product's own line. */
    private function applyStockIn(Product $product, ?ProductVariant $variant, array $data): void
    {
        $supplierId = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id'])?->id : null;
        $brandId = ! empty($data['brand_id']) ? Brand::find($data['brand_id'])?->id : null;
        $qty = (int) $data['qty'];
        $paidCost = (float) $data['cost'];
        $paidPrice = isset($data['price']) ? (float) $data['price'] : null;

        $line = ProductSource::upsertLine(
            Tenancy::id(), $product->id, $variant?->id,
            $supplierId, $brandId, $qty, $paidCost, $paidPrice,
            (float) ($variant?->price ?? $product->price)
        );

        // mirror: cost OVERWRITES to this line's new cost — never a
        // cross-supplier blend once lines are the real bookkeeping (see
        // class docblock); stock always just adds, same as before
        if ($variant) {
            $variant->applyStockDelta($qty);
            $variant->update(['cost' => $line->cost]);
            $product->applyStockDelta($qty);
        } else {
            $product->applyStockDelta($qty);
            $product->update(['cost' => $line->cost]);
        }

        IntakeLedger::record($product, $variant, $qty, $paidCost, $variant ? 'variant_stock_in' : 'product_stock_in', $supplierId, $line->id);
        \App\Support\SupplierPayable::apply($supplierId, $data['payment_method'] ?? null, $paidCost * $qty);

        Activity::log(
            'product.source.stockIn',
            "'{$product->name}'".($variant ? " ({$variant->label()})" : '')." — {$line->pickerLabel()} থেকে {$qty} স্টক যোগ হয়েছে (ক্রয় ৳{$paidCost})।",
            $line
        );
    }
}
