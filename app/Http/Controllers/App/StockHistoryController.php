<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Damage;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\StockCount;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Damage, stock-count reconciliation, and customer returns each already
 * wrote a permanent, timestamped row to the database - but none of them
 * had anywhere an owner could actually browse "what happened and when".
 * Once recorded, the entry was invisible again except as a lump sum on
 * Accounts/Reports. This is that missing history screen, one shared page
 * with a tab per record type (owner-only, matching the Activity Log).
 */
class StockHistoryController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->query('type', 'damage');

        $records = match ($type) {
            'count' => $this->stockCounts(),
            'return' => SalesReturn::with(['product:id,name,emoji', 'variant:id,size,color', 'user:id,name'])
                ->latest('id')->paginate(20)->withQueryString(),
            default => Damage::with(['product:id,name,emoji', 'variant:id,size,color'])
                ->latest('id')->paginate(20)->withQueryString(),
        };

        return Inertia::render('App/StockHistory/Index', ['type' => $type, 'records' => $records]);
    }

    /** StockCount.changes is a JSON array of {product_id, from, to} (plus
     *  product_variant_id for a variant row — see StockCountController)
     *  with no product/variant name baked in — resolve every id referenced
     *  on this page of results in one batch query rather than one lookup
     *  per row. */
    private function stockCounts()
    {
        $page = StockCount::latest('id')->paginate(20)->withQueryString();

        $productIds = collect($page->items())
            ->flatMap(fn ($c) => collect($c->changes)->pluck('product_id'))
            ->unique()->values();
        $names = Product::whereIn('id', $productIds)->pluck('name', 'id');

        $variantIds = collect($page->items())
            ->flatMap(fn ($c) => collect($c->changes)->pluck('product_variant_id'))
            ->filter()->unique()->values();
        $variants = \App\Models\ProductVariant::whereIn('id', $variantIds)->get(['id', 'size', 'color'])->keyBy('id');

        $page->getCollection()->transform(function ($count) use ($names, $variants) {
            $count->changes = collect($count->changes)->map(function ($c) use ($names, $variants) {
                $variant = ! empty($c['product_variant_id']) ? $variants->get($c['product_variant_id']) : null;

                return [
                    ...$c,
                    'product_name' => $names->get($c['product_id'], '?').($variant ? ' ('.$variant->label().')' : ''),
                ];
            })->all();

            return $count;
        });

        return $page;
    }
}
