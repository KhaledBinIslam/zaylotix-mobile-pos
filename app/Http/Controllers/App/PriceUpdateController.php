<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Support\Activity;
use App\Support\ArticleVariant;
use App\Support\CatalogSync;
use App\Support\ColorBarcodeResolver;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * "product wise serial wise size color wise group wise" price update,
 * reusable whenever needed (Baby Park & Liberty, Liberty Bali) — select any
 * mix of whole products and individual size/colour variants, type each
 * one's own new cost/sell price, save together.
 *
 * Mirrors ProductController::update()'s cost/price write and branch-sync
 * behaviour, and ProductVariantController::update()'s variant-level write —
 * this is the same cost/price columns, just applied to many rows at once
 * instead of walking each item's own edit sheet.
 *
 * When an article's (barcode-carrying product's) cost changes, its own
 * barcode and every colour's barcode get rebuilt (ArticleVariant::
 * restampCost(), ColorBarcodeResolver::resolve() — both alias the old
 * value, never delete it). An already-PRINTED size/colour sticker
 * (ProductVariant.barcode) is deliberately never touched here — the same
 * "never touches an existing ProductVariant row" rule
 * articles:regenerate-barcodes already follows — so nothing physically
 * printed stops scanning; only the next new size/reprint picks up the new
 * cost.
 */
class PriceUpdateController extends Controller
{
    public function index(Request $request)
    {
        $categoryId = $request->get('category_id');
        $search = trim((string) $request->get('q', ''));

        $query = Product::with(['variants' => fn ($q) => $q->whereNull('deleted_at')->orderBy('size')])
            ->whereNull('deleted_at')
            ->when($categoryId, fn ($q) => $q->whereIn('category_id', ProductCategory::idsIncludingChildren((int) $categoryId)))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            }))
            ->orderBy('name');

        $products = $query->get()->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'barcode' => $p->barcode,
            'cost' => (float) $p->cost,
            'price' => (float) $p->price,
            'is_branch_copy' => $p->master_product_id !== null,
            'variants' => $p->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'size' => $v->size,
                'color' => $v->color,
                'barcode' => $v->barcode,
                'cost' => $v->cost !== null ? (float) $v->cost : (float) $p->cost,
                'price' => $v->price !== null ? (float) $v->price : (float) $p->price,
            ])->values(),
        ])->values();

        return Inertia::render('App/Prices/Index', [
            'products' => $products,
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name', 'name_en', 'parent_id']),
            'filters' => ['category_id' => $categoryId, 'q' => $search],
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $shop = Tenancy::shop();

        $data = $request->validate([
            'products' => ['array'],
            'products.*.id' => ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
            'products.*.cost' => ['required', 'numeric', 'min:0'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
            'variants' => ['array'],
            'variants.*.id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('shop_id', Tenancy::id())],
            'variants.*.cost' => ['required', 'numeric', 'min:0'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        if (empty($data['products']) && empty($data['variants'])) {
            return back()->withErrors(['selection' => 'কিছু সিলেক্ট করা হয়নি।']);
        }

        $productCount = 0;
        $variantCount = 0;
        $rebuiltBarcodes = 0;

        DB::transaction(function () use ($shop, $data, &$productCount, &$variantCount, &$rebuiltBarcodes) {
            foreach ($data['products'] ?? [] as $row) {
                $product = Product::whereKey($row['id'])->lockForUpdate()->first();
                // a branch's own linked copy never owns its price — same
                // rule ProductController::update() enforces; silently
                // skipped rather than erroring, since the UI never offers
                // a new-price box for one of these in the first place
                if (! $product || $product->master_product_id) {
                    continue;
                }

                $newCost = round((float) $row['cost'], 2);
                $newPrice = round((float) $row['price'], 2);
                $before = ['cost' => (float) $product->cost, 'price' => (float) $product->price];
                $costChanged = $newCost !== round($before['cost'], 2);

                if ($costChanged && ArticleVariant::isArticle($product)) {
                    $newArticleBarcode = ArticleVariant::restampCost($shop, $product, (int) $newCost);
                    if ($newArticleBarcode !== null && $newArticleBarcode !== $product->barcode) {
                        $product->barcode = $newArticleBarcode;
                        $rebuiltBarcodes++;

                        $colors = ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')
                            ->whereNotNull('color')->distinct()->pluck('color');
                        foreach ($colors as $color) {
                            ColorBarcodeResolver::resolve($shop, $product, $color, $newArticleBarcode);
                        }
                    }
                }

                $product->cost = $newCost;
                $product->price = $newPrice;
                $product->save();
                $productCount++;

                if ($before['cost'] !== $newCost || $before['price'] !== $newPrice) {
                    Activity::log(
                        'product.priceUpdate',
                        "'{$product->name}' এর দাম বদলানো হয়েছে — cost ".number_format($before['cost']).' → '.number_format($newCost).', sell '.number_format($before['price']).' → '.number_format($newPrice).'।',
                        $product,
                        ['before' => $before, 'after' => ['cost' => $newCost, 'price' => $newPrice]]
                    );
                }

                if (! $shop->parent_shop_id && $shop->hasFeature('shared_catalog')) {
                    CatalogSync::syncProductToBranches($shop, $product);
                }
            }

            foreach ($data['variants'] ?? [] as $row) {
                $variant = ProductVariant::whereKey($row['id'])->lockForUpdate()->first();
                if (! $variant) {
                    continue;
                }
                $vBefore = ['cost' => (float) $variant->cost, 'price' => (float) $variant->price];
                $vAfter = ['cost' => round((float) $row['cost'], 2), 'price' => round((float) $row['price'], 2)];

                $variant->update(['cost' => $vAfter['cost'], 'price' => $vAfter['price']]);
                $variantCount++;

                if ($vBefore !== $vAfter) {
                    Activity::log(
                        'product.variant.priceUpdate',
                        "'{$variant->product?->name}' ({$variant->label()}) এর দাম বদলানো হয়েছে — cost ".number_format($vBefore['cost']).' → '.number_format($vAfter['cost']).', sell '.number_format($vBefore['price']).' → '.number_format($vAfter['price']).'।',
                        $variant,
                        ['before' => $vBefore, 'after' => $vAfter]
                    );
                }
            }
        });

        $msg = "{$productCount}টা প্রোডাক্ট, {$variantCount}টা ভ্যারিয়েন্টের দাম আপডেট হলো।";
        if ($rebuiltBarcodes > 0) {
            $msg .= " {$rebuiltBarcodes}টা আর্টিকেলের বারকোড নতুন cost অনুযায়ী আপডেট হয়েছে (আগের প্রিন্ট করা স্টিকার আগের মতোই কাজ করবে, শুধু নতুন সাইজ/রিপ্রিন্টে নতুন cost বসবে)।";
        }

        return back()->with('success', $msg);
    }
}
