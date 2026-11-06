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

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'qty' => ['required', 'integer', 'min:1'],
            'cost' => ['required', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
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

        Activity::log(
            'product.source.stockIn',
            "'{$product->name}'".($variant ? " ({$variant->label()})" : '')." — {$line->pickerLabel()} থেকে {$qty} স্টক যোগ হয়েছে (ক্রয় ৳{$paidCost})।",
            $line
        );
    }
}
