<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * "ডেড স্টক ক্লিয়ারেন্স" — Phase 3 feature 3, see docs/FIX_PLAN.md's design
 * note. No new discount mechanism: "মার্কডাউন" here just sets the existing
 * `products.discount_price` field, which (as of this same phase — a real
 * bug found while building this) now actually applies at checkout
 * (PosController::performCheckout()). A "বান্ডেল" (BOGO/combo) suggestion
 * already has its own dedicated, fuller tool — Promotions — linked from
 * this page rather than rebuilt here.
 */
class DeadStockController extends Controller
{
    public function index()
    {
        $shop = Tenancy::shop();
        $cutoff = now()->subDays($shop->dead_stock_days)->toDateString();
        $hasBatchTracking = $shop->hasFeature('batch_tracking');

        // a product that sold at least once since the cutoff is NOT dead
        // stock, regardless of how little — this only ever needs to know
        // "did anything move", not how much
        $recentlySoldProductIds = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.shop_id', Tenancy::id())
            ->whereDate('sales.date', '>=', $cutoff)
            ->whereNotNull('sale_items.product_id')
            ->distinct()
            ->pluck('sale_items.product_id');

        $query = Product::whereNotIn('id', $recentlySoldProductIds)
            ->where('stock', '>', 0)
            // a variant/weight-based product's "price" isn't what a
            // cashier actually charges per unit sold — markdown via a flat
            // discount_price only makes unambiguous sense for a plain
            // product, same scope the checkout fix itself covers
            ->where('sold_by_weight', false)
            ->whereDoesntHave('variants');

        if ($hasBatchTracking) {
            $query->with('nearestBatch');
        }

        $products = $query->get()->map(function ($p) use ($hasBatchTracking) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'emoji' => $p->emoji,
                'stock' => (float) $p->stock,
                'price' => (float) $p->price,
                'discount_price' => $p->discount_price !== null ? (float) $p->discount_price : null,
                'stock_value' => (float) $p->stock * (float) $p->price,
                'expiry_date' => $hasBatchTracking ? $p->nearestBatch?->expiry_date?->toDateString() : null,
            ];
        });

        // nearest-expiry-first (nulls last), then highest tied-up stock
        // value first — see the master prompt's own ordering requirement
        $products = $products->sortBy([
            fn ($p) => [$p['expiry_date'] ?? '9999-99-99', -$p['stock_value']],
        ])->values();

        return Inertia::render('App/DeadStock/Index', [
            'deadStockDays' => $shop->dead_stock_days,
            'cutoffDate' => $cutoff,
            'hasBatchTracking' => $hasBatchTracking,
            'products' => $products,
        ]);
    }

    public function updateDays(Request $request)
    {
        $data = $request->validate(['dead_stock_days' => ['required', 'integer', 'min:7', 'max:365']]);

        Tenancy::shop()->update($data);

        return back()->with('success', 'সেটিং সংরক্ষণ করা হয়েছে।');
    }

    /** Sets discount_price for every selected product — one shared percent off its own current price, rounded to the nearest taka like every other price shown in this app. */
    public function markdown(Request $request)
    {
        $data = $request->validate([
            'percent' => ['required', 'numeric', 'min:1', 'max:90'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
        ]);

        $products = Product::whereIn('id', $data['product_ids'])->get();
        foreach ($products as $product) {
            $discounted = round((float) $product->price * (1 - $data['percent'] / 100));
            // discount_price must be strictly less than price (see
            // StoreProductRequest) — a 1%-off on a cheap item can round to
            // the same taka, which would otherwise throw on save
            if ($discounted >= (float) $product->price) {
                $discounted = (float) $product->price - 1;
            }
            if ($discounted < 0) {
                continue;
            }
            $product->update(['discount_price' => $discounted]);
        }

        return back()->with('success', count($products).' টা পণ্যে মার্কডাউন প্রয়োগ করা হয়েছে।');
    }

    /** Undo — clears discount_price back to "no markdown", same full price as before. */
    public function clearMarkdown(Request $request)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('shop_id', Tenancy::id())],
        ]);

        Product::whereIn('id', $data['product_ids'])->update(['discount_price' => null]);

        return back()->with('success', 'মার্কডাউন তুলে নেওয়া হয়েছে।');
    }
}
