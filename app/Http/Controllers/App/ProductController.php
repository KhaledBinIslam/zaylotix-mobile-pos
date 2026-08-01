<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreProductRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $with = ['category', 'unit', 'productUnits.unit', 'variants'];
        if (Tenancy::shop()?->hasFeature('batch_tracking')) {
            $with[] = 'nearestBatch';
        }

        $q = trim((string) $request->get('q', ''));
        $categoryId = $request->get('category_id');
        $company = $request->get('company');
        $genericName = $request->get('generic_name');

        // paginated — an unbounded ->get() here was a real scale risk for a
        // pharmacy/supershop with a large catalog (every product's full
        // relation tree shipped to the browser on every page load,
        // regardless of how much of it was ever scrolled to). Search/filter
        // still run server-side against the whole table, only the *rendered
        // page* is capped, so finding a specific product by name/barcode
        // still searches everything, not just the current page.
        $products = Product::with($with)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('name_en', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "%{$q}%")
                ->orWhere('generic_name', 'like', "%{$q}%")))
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($company, fn ($query) => $query->where('company', $company))
            ->when($genericName, fn ($query) => $query->where('generic_name', $genericName))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // computed against the WHOLE catalog, independent of the current
        // page/search/filter — these are dashboard-style totals, not a
        // summary of whatever 30 rows happen to be showing right now
        $lowStockCount = Product::where('sold_by_weight', false)->whereBetween('stock', [0.01, 6])->count()
            + Product::where('sold_by_weight', true)->whereBetween('stock', [0.01, 1])->count();
        $stats = [
            'total' => Product::count(),
            'low_stock' => $lowStockCount,
            'out_of_stock' => Product::where('stock', '<=', 0)->count(),
            'expiring_soon' => Tenancy::shop()?->hasFeature('batch_tracking')
                ? ProductBatch::available()->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now()->addDays(60))->distinct('product_id')->count('product_id')
                : 0,
            'category_counts' => Product::whereNotNull('category_id')->selectRaw('category_id, count(*) as c')->groupBy('category_id')->pluck('c', 'category_id'),
        ];

        return Inertia::render('App/Stock/Index', [
            'products' => $products,
            'categories' => ProductCategory::orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'stats' => $stats,
            'q' => $q,
            'categoryId' => $categoryId,
            'company' => $company,
            'genericName' => $genericName,
            // distinct, non-null values only — feeds the company/generic
            // filter dropdowns; a shop that's never used either field just
            // gets an empty list and the dropdown quietly doesn't show
            'companies' => Product::whereNotNull('company')->distinct()->orderBy('company')->pluck('company'),
            'genericNames' => Product::whereNotNull('generic_name')->distinct()->orderBy('generic_name')->pluck('generic_name'),
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();

        [$categoryId, $unitId] = DB::transaction(function () use ($data) {
            $categoryId = $data['category_id'] ?? null;
            if (! $categoryId && ! empty($data['new_category_name'])) {
                $categoryId = ProductCategory::create([
                    'shop_id' => Tenancy::id(),
                    'name' => $data['new_category_name'],
                    'name_en' => $data['new_category_name'],
                    'emoji' => '📦',
                ])->id;
            }

            $unitId = $data['unit_id'] ?? null;
            if (! $unitId && ! empty($data['new_unit_name'])) {
                $unitId = Unit::create([
                    'shop_id' => Tenancy::id(),
                    'name' => $data['new_unit_name'],
                    'name_en' => $data['new_unit_name'],
                    'code' => null,
                ])->id;
            }

            return [$categoryId, $unitId];
        });

        $product = Product::create([
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? $data['name'],
            'generic_name' => $data['generic_name'] ?? null,
            'company' => $data['company'] ?? null,
            'shelf_location' => $data['shelf_location'] ?? null,
            'requires_prescription' => $data['requires_prescription'] ?? false,
            'emoji' => $data['emoji'] ?? '📦',
            'photo_path' => $request->hasFile('photo') ? $request->file('photo')->store('product-photos', 'public') : null,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'barcode' => $data['barcode'] ?? null,
            'sold_by_weight' => $data['sold_by_weight'] ?? false,
            'weight_unit' => ($data['sold_by_weight'] ?? false) ? $data['weight_unit'] : null,
            'cost' => $data['cost'],
            'price' => $data['price'],
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'discount_price' => $data['discount_price'] ?? null,
            'stock' => $data['stock'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'size' => $data['size'] ?? null,
            'color' => $data['color'] ?? null,
            'imei' => $data['imei'] ?? null,
            'reorder_point' => $data['reorder_point'] ?? null,
        ]);

        return back()->with('success', "{$product->name} added.");
    }

    public function update(StoreProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        $data = $request->validated();

        // mirrors the guards in ProductVariantController/ProductUnitController
        // — a weighed product's fractional stock and a variant/pack-size's
        // whole-unit stock math can't both claim to describe the same product
        if (($data['sold_by_weight'] ?? false) && ($product->variants()->exists() || $product->productUnits()->exists())) {
            return back()->withErrors(['sold_by_weight' => "{$product->name}-এ ইতিমধ্যে ভ্যারিয়েন্ট/প্যাক সাইজ আছে — একসাথে ওজন-ভিত্তিক বিক্রি চালু করা যাবে না।"]);
        }

        [$categoryId, $unitId] = DB::transaction(function () use ($data) {
            $categoryId = $data['category_id'] ?? null;
            if (! $categoryId && ! empty($data['new_category_name'])) {
                $categoryId = ProductCategory::create([
                    'shop_id' => Tenancy::id(),
                    'name' => $data['new_category_name'],
                    'name_en' => $data['new_category_name'],
                    'emoji' => '📦',
                ])->id;
            }

            $unitId = $data['unit_id'] ?? null;
            if (! $unitId && ! empty($data['new_unit_name'])) {
                $unitId = Unit::create([
                    'shop_id' => Tenancy::id(),
                    'name' => $data['new_unit_name'],
                    'name_en' => $data['new_unit_name'],
                    'code' => null,
                ])->id;
            }

            return [$categoryId, $unitId];
        });

        $fields = [
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? $data['name'],
            'generic_name' => $data['generic_name'] ?? null,
            'company' => $data['company'] ?? null,
            'shelf_location' => $data['shelf_location'] ?? null,
            'requires_prescription' => $data['requires_prescription'] ?? false,
            'emoji' => $data['emoji'] ?? $product->emoji,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'barcode' => $data['barcode'] ?? null,
            'sold_by_weight' => $data['sold_by_weight'] ?? false,
            'weight_unit' => ($data['sold_by_weight'] ?? false) ? $data['weight_unit'] : null,
            'cost' => $data['cost'],
            'price' => $data['price'],
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'discount_price' => $data['discount_price'] ?? null,
            'stock' => $data['stock'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'size' => $data['size'] ?? null,
            'color' => $data['color'] ?? null,
            'imei' => $data['imei'] ?? null,
            'reorder_point' => $data['reorder_point'] ?? null,
        ];

        // for a variant product, `stock` is a live-maintained sum of
        // variants.stock (kept in sync by ProductVariantController) — the
        // edit form only ever shows that same total back, so writing it
        // here verbatim is harmless today, but never let this endpoint be
        // the one that can drift the sum away from its parts
        if ($product->variants()->exists()) {
            unset($fields['stock']);
        }

        if ($request->hasFile('photo') || ! empty($data['remove_photo'])) {
            if ($product->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $fields['photo_path'] = $request->hasFile('photo') ? $request->file('photo')->store('product-photos', 'public') : null;
        }

        $product->update($fields);

        return back()->with('success', "{$product->name} updated.");
    }

    public function destroy(Product $product)
    {
        $this->authorize('delete', $product);
        $product->delete(); // soft delete — keeps historical sale_items intact

        return back()->with('success', 'Product removed.');
    }

    /** Pick a product, add received qty ("stock in"). */
    public function stockIn(Product $product)
    {
        $this->authorize('update', $product);

        $data = request()->validate([
            'qty' => ['required', 'numeric', 'min:0.001', ...($product->sold_by_weight ? [] : ['integer'])],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'imeis' => ['nullable', 'string'],
            'warranty_expiry' => ['nullable', 'date'],
        ]);

        if ($product->variants()->exists()) {
            abort(422, 'এটি ভ্যারিয়েন্ট পণ্য — প্রতিটি ভ্যারিয়েন্টের স্টক আলাদাভাবে যোগ করুন।');
        }

        $imeis = \App\Support\SerialStock::parseList($data['imeis'] ?? null);
        if ($imeis && count($imeis) !== (int) $data['qty']) {
            $given = count($imeis);
            return back()->withErrors(['imeis' => "IMEI সংখ্যা ({$given}) স্টকের পরিমাণের ({$data['qty']}) সাথে মিলছে না।"]);
        }

        DB::transaction(function () use ($product, $data, $imeis) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();
            \App\Support\StockIn::apply($locked, (float) $data['qty'], $data['cost'] ?? null);

            $shop = \App\Support\Tenancy::shop();
            // batch/FEFO tracking is an integer-quantity pharmacy layer — a
            // weighed product's fractional stock-in has no batch of its own
            // to describe, so it's skipped rather than truncated into one
            if ($shop?->hasFeature('batch_tracking') && ! $locked->sold_by_weight) {
                \App\Support\BatchStock::receive($locked, (int) $data['qty'], $data['batch_no'] ?? null, $data['expiry_date'] ?? null, $data['cost'] ?? null);
            }
            if ($shop?->hasFeature('serial_tracking') && $imeis) {
                \App\Support\SerialStock::receive($locked, $imeis, $data['warranty_expiry'] ?? null, $data['cost'] ?? null);
            }

            \App\Support\Activity::log('product.stockIn', "'{$locked->name}'-এ {$data['qty']} স্টক যোগ করা হয়েছে।", $locked, [
                'qty' => $data['qty'], 'cost' => $data['cost'] ?? null,
            ]);
        });

        return back()->with('success', 'Stock updated.');
    }
}
