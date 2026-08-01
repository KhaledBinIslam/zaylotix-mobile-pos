<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Kitchen Display Screen — a live-polling read/act screen for whoever's
 * cooking, distinct from the front-of-house served_at (waiter physically
 * brought the food to the table, tracked in TableOrderController) and from
 * kot_printed_at (sent to the kitchen at all, printed or WhatsApp'd). This
 * is the kitchen's own "finished preparing it" moment.
 */
class KdsController extends Controller
{
    public function index()
    {
        $openOrders = TableOrder::where('status', 'open')
            ->with(['table:id,name', 'items' => fn ($q) => $q->whereNull('sale_id')->whereNull('cooked_at')->orderBy('id')])
            ->whereHas('items', fn ($q) => $q->whereNull('sale_id')->whereNull('cooked_at'))
            ->orderBy('opened_at')
            ->get()
            ->map(fn (TableOrder $o) => [
                'id' => $o->id,
                'table_name' => $o->table?->name,
                'order_source' => $o->order_source,
                'opened_at' => $o->opened_at,
                'items' => $o->items->map(fn (TableOrderItem $i) => [
                    'id' => $i->id, 'product_name' => $i->product_name, 'qty' => $i->qty,
                ]),
            ]);

        // recently-cooked, still-open orders — a cook glancing at the
        // "Cooked" tab wants "what did I just finish", not a lifetime log
        $cookedOrders = TableOrder::where('status', 'open')
            ->with(['table:id,name', 'items' => fn ($q) => $q->whereNull('sale_id')->whereNotNull('cooked_at')->where('cooked_at', '>=', now()->subHours(6))->orderByDesc('cooked_at')])
            ->whereHas('items', fn ($q) => $q->whereNull('sale_id')->whereNotNull('cooked_at')->where('cooked_at', '>=', now()->subHours(6)))
            ->orderBy('opened_at')
            ->get()
            ->map(fn (TableOrder $o) => [
                'id' => $o->id,
                'table_name' => $o->table?->name,
                'order_source' => $o->order_source,
                'items' => $o->items->map(fn (TableOrderItem $i) => [
                    'id' => $i->id, 'product_name' => $i->product_name, 'qty' => $i->qty, 'cooked_at' => $i->cooked_at,
                ]),
            ]);

        return Inertia::render('App/Kds/Index', [
            'openOrders' => $openOrders,
            'cookedOrders' => $cookedOrders,
        ]);
    }

    public function cook(TableOrderItem $tableOrderItem)
    {
        $tableOrderItem->update(['cooked_at' => now()]);

        return back();
    }

    public function cookAll(TableOrder $tableOrder)
    {
        DB::transaction(function () use ($tableOrder) {
            TableOrderItem::where('table_order_id', $tableOrder->id)
                ->whereNull('sale_id')
                ->whereNull('cooked_at')
                ->update(['cooked_at' => now()]);
        });

        return back();
    }
}
