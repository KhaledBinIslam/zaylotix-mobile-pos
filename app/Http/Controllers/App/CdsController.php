<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Inertia\Inertia;

/**
 * Customer Display Screen — read-only, meant for a second monitor/tablet
 * facing the customer: browsable menu on one side, live status of today's
 * orders on the other. No cart, no prices editable, nothing here can change
 * any order — it only ever reads.
 */
class CdsController extends Controller
{
    public function index()
    {
        return Inertia::render('App/Cds/Index', [
            'products' => Product::where('stock', '>', 0)->orderBy('name')->get(['id', 'name', 'emoji', 'photo_path', 'price', 'category_id']),
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name', 'emoji']),
            'todaysOrders' => TableOrder::where('status', 'open')
                ->whereDate('opened_at', now()->toDateString())
                ->with(['table:id,name', 'items' => fn ($q) => $q->whereNull('sale_id')->orderBy('id')])
                ->orderBy('opened_at')
                ->get()
                ->map(fn (TableOrder $o) => [
                    'id' => $o->id,
                    'table_name' => $o->table?->name,
                    'order_source' => $o->order_source,
                    'opened_at' => $o->opened_at,
                    'items' => $o->items->map(fn (TableOrderItem $i) => [
                        'id' => $i->id,
                        'product_name' => $i->product_name,
                        'qty' => $i->qty,
                        'status' => $i->served_at ? 'served' : ($i->cooked_at ? 'cooked' : 'pending'),
                    ]),
                ]),
        ]);
    }
}
