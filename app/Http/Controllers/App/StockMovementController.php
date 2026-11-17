<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Damage;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\StockTransfer;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Stock Movement Register" (Khaled's explicit ask, referencing another
 * product's version of this screen): every unit in and out, which
 * product, which branch, when, how many, and why — across the whole
 * business, not just the current branch. Entirely read-only/reporting;
 * touches no existing data or write path. Pulls from the real tables
 * that already record each kind of movement (Purchase, Sale, SalesReturn,
 * Damage, StockTransfer) rather than inventing a new movements table —
 * StockHistoryController already does the same "browse what already
 * happened" job for damage/count/return individually; this unifies all
 * movement types into one chronological feed instead of separate tabs.
 */
class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'owner', 403);

        $shop = Tenancy::shop();
        $shopIds = Tenancy::businessShopIds($shop->id);
        $shopNames = Shop::withoutGlobalScopes()->whereIn('id', $shopIds)->pluck('name', 'id');

        $productQuery = trim((string) $request->get('product', ''));
        $typeFilter = $request->get('type', 'all');
        $limit = 80;

        $rows = collect();

        if (in_array($typeFilter, ['all', 'purchase'], true)) {
            $rows = $rows->concat(
                Purchase::withoutGlobalScopes()->whereIn('shop_id', $shopIds)
                    ->whereNotNull('product_id')->where('status', '!=', 'cancelled')
                    ->with('product:id,name')
                    ->when($productQuery, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$productQuery}%")))
                    ->latest('date')->limit($limit)->get()
                    ->map(fn (Purchase $p) => [
                        'date' => $p->date->toDateString(), 'type' => 'purchase', 'type_label' => 'ক্রয়',
                        'product_name' => $p->product?->name ?? '—', 'branch' => $shopNames->get($p->shop_id, '—'),
                        'qty_in' => (float) $p->qty, 'qty_out' => 0, 'ref' => $p->memo, 'note' => $p->supplier,
                    ])
            );
        }

        if (in_array($typeFilter, ['all', 'sale'], true)) {
            $rows = $rows->concat(
                SaleItem::withoutGlobalScopes()->whereIn('shop_id', $shopIds)
                    ->whereHas('sale', fn ($q) => $q->withoutGlobalScopes()->where('sale_type', '!=', 'exchange'))
                    ->with(['sale:id,invoice_no,date,shop_id'])
                    ->when($productQuery, fn ($q) => $q->where('product_name', 'like', "%{$productQuery}%"))
                    ->latest('id')->limit($limit)->get()
                    ->map(fn (SaleItem $i) => [
                        'date' => $i->sale?->date?->toDateString() ?? '—', 'type' => 'sale', 'type_label' => 'বিক্রয়',
                        'product_name' => $i->product_name, 'branch' => $shopNames->get($i->shop_id, '—'),
                        'qty_in' => 0, 'qty_out' => (float) $i->qty, 'ref' => $i->sale?->invoice_no, 'note' => null,
                    ])
            );
        }

        if (in_array($typeFilter, ['all', 'return'], true)) {
            $rows = $rows->concat(
                SalesReturn::withoutGlobalScopes()->whereIn('shop_id', $shopIds)
                    ->where('condition', 'resalable')
                    ->with('product:id,name')
                    ->when($productQuery, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$productQuery}%")))
                    ->latest('date')->limit($limit)->get()
                    ->map(fn (SalesReturn $r) => [
                        'date' => $r->date?->toDateString() ?? '—', 'type' => 'return', 'type_label' => 'ফেরত (রিস্টক)',
                        'product_name' => $r->product?->name ?? '—', 'branch' => $shopNames->get($r->shop_id, '—'),
                        'qty_in' => (float) $r->qty, 'qty_out' => 0, 'ref' => null, 'note' => $r->reason,
                    ])
            );
        }

        if (in_array($typeFilter, ['all', 'damage'], true)) {
            $rows = $rows->concat(
                Damage::withoutGlobalScopes()->whereIn('shop_id', $shopIds)
                    ->with('product:id,name')
                    ->when($productQuery, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$productQuery}%")))
                    ->latest('date')->limit($limit)->get()
                    ->map(fn (Damage $d) => [
                        'date' => $d->date?->toDateString() ?? '—', 'type' => 'damage', 'type_label' => 'ক্ষতি/নষ্ট',
                        'product_name' => $d->product?->name ?? '—', 'branch' => $shopNames->get($d->shop_id, '—'),
                        'qty_in' => 0, 'qty_out' => (float) $d->qty, 'ref' => null, 'note' => $d->reason,
                    ])
            );
        }

        if (in_array($typeFilter, ['all', 'transfer'], true)) {
            $transfers = StockTransfer::withoutGlobalScopes()
                ->where(fn ($q) => $q->whereIn('from_shop_id', $shopIds)->orWhereIn('to_shop_id', $shopIds))
                ->where('status', 'completed')
                ->when($productQuery, fn ($q) => $q->where('product_name', 'like', "%{$productQuery}%"))
                ->latest('id')->limit($limit)->get();

            foreach ($transfers as $t) {
                $rows->push([
                    'date' => $t->updated_at->toDateString(), 'type' => 'transfer', 'type_label' => 'ট্রান্সফার-আউট',
                    'product_name' => $t->product_name, 'branch' => $shopNames->get($t->from_shop_id, '—'),
                    'qty_in' => 0, 'qty_out' => (float) $t->qty, 'ref' => $shopNames->get($t->to_shop_id, '—'), 'note' => 'প্রাপক শাখা',
                ]);
                $rows->push([
                    'date' => $t->updated_at->toDateString(), 'type' => 'transfer', 'type_label' => 'ট্রান্সফার-ইন',
                    'product_name' => $t->product_name, 'branch' => $shopNames->get($t->to_shop_id, '—'),
                    'qty_in' => (float) $t->qty, 'qty_out' => 0, 'ref' => $shopNames->get($t->from_shop_id, '—'), 'note' => 'প্রেরক শাখা',
                ]);
            }
        }

        $sorted = $rows->sortByDesc('date')->values()->take(200);

        return Inertia::render('App/StockMovements/Index', [
            'movements' => $sorted,
            'filters' => ['product' => $productQuery, 'type' => $typeFilter],
            'totals' => [
                'in' => round($sorted->sum('qty_in'), 2),
                'out' => round($sorted->sum('qty_out'), 2),
            ],
        ]);
    }
}
