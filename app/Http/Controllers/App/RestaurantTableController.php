<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\RestaurantTable;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RestaurantTableController extends Controller
{
    public function index()
    {
        // scoped to whereNull('sale_id') — a split-billed-away item is no
        // longer part of the table's still-running (unpaid) total
        $tables = RestaurantTable::with(['openOrder.items' => fn ($q) => $q->whereNull('sale_id')])
            ->orderBy('name')
            ->get()
            ->map(fn (RestaurantTable $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status,
                'open_order_id' => $t->openOrder?->id,
                'total' => $t->openOrder?->total() ?? 0,
                'item_count' => $t->openOrder?->items->sum('qty') ?? 0,
                'waiter_name' => $t->openOrder?->waiter_name,
                'order_source' => $t->openOrder?->order_source,
                // lets the cashier spot, at a glance across the whole grid,
                // which occupied tables still have something the kitchen
                // hasn't been told about yet — without opening each one
                'has_unprinted' => $t->openOrder?->items->whereNull('kot_printed_at')->isNotEmpty() ?? false,
                'opened_at' => $t->openOrder?->created_at?->diffForHumans(),
            ]);

        // takeaway/parcel orders have no restaurant_table_id at all (see
        // openTakeaway()) so they never appear in the table grid above —
        // this is the only place a cashier can get back to one already in progress
        $takeawayOrders = TableOrder::whereNull('restaurant_table_id')
            ->where('status', 'open')
            ->with(['items' => fn ($q) => $q->whereNull('sale_id')])
            ->orderBy('opened_at')
            ->get()
            ->map(fn (TableOrder $o) => [
                'id' => $o->id,
                'order_source' => $o->order_source,
                'total' => $o->total(),
                'item_count' => $o->items->sum('qty'),
                'opened_at' => $o->created_at?->diffForHumans(),
            ]);

        return Inertia::render('App/Restaurant/Tables', ['tables' => $tables, 'takeawayOrders' => $takeawayOrders]);
    }

    /**
     * Starts an order with no physical table at all — a takeaway/parcel
     * customer has no seat to occupy, so forcing the cashier to pick one
     * (as dine-in requires) made no sense and was the actual point of
     * confusion this method exists to remove.
     */
    public function openTakeaway()
    {
        $order = TableOrder::create([
            'restaurant_table_id' => null,
            'status' => 'open',
            'order_source' => 'takeaway',
            'opened_at' => now(),
        ]);

        return redirect()->route('app.restaurant.orders.show', $order->id);
    }

    /**
     * Food-first flow, per Khaled's explicit request ("age food select kore
     * — table naki gateway seta pore select korbe"): starts a brand-new
     * order with neither a table nor a fixed channel chosen yet, straight
     * onto the menu screen. order_source defaults to dine_in purely as a
     * DB default — it's never shown/asked at this point, only decided
     * later on the order screen (see TableOrderController::updateMeta /
     * assignTable), same idea as openTakeaway() but without committing to
     * a channel upfront either.
     */
    public function openOrder()
    {
        $order = TableOrder::create([
            'restaurant_table_id' => null,
            'status' => 'open',
            'order_source' => 'dine_in',
            'opened_at' => now(),
        ]);

        return redirect()->route('app.restaurant.orders.show', $order->id);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50']]);

        RestaurantTable::create(['name' => $data['name'], 'status' => 'free']);

        return back()->with('success', 'টেবিল যোগ হয়েছে।');
    }

    public function destroy(RestaurantTable $restaurantTable)
    {
        if ($restaurantTable->status === 'occupied') {
            return back()->withErrors(['name' => 'এই টেবিলে চলমান অর্ডার আছে — আগে বিল করুন বা বাতিল করুন।']);
        }

        $restaurantTable->delete();

        return back()->with('success', 'টেবিল মুছে ফেলা হয়েছে।');
    }

    /**
     * Seats a party — opens a fresh tab for this table.
     *
     * The table row is locked before the occupied/free check so two
     * near-simultaneous "open" taps on the same free table can't both pass
     * the check and each create their own TableOrder — the second caller
     * always sees the first one's already-committed 'occupied' status.
     */
    public function open(RestaurantTable $restaurantTable)
    {
        $order = DB::transaction(function () use ($restaurantTable) {
            $lockedTable = RestaurantTable::whereKey($restaurantTable->id)->lockForUpdate()->first();

            if ($lockedTable->status === 'occupied') {
                $existing = TableOrder::where('restaurant_table_id', $lockedTable->id)->where('status', 'open')->first();
                if ($existing) {
                    return $existing;
                }
            }

            $order = TableOrder::create([
                'restaurant_table_id' => $lockedTable->id,
                'status' => 'open',
                'opened_at' => now(),
            ]);
            $lockedTable->update(['status' => 'occupied']);

            return $order;
        });

        return redirect()->route('app.restaurant.orders.show', $order->id);
    }

    /**
     * Moves an open order to a different (currently free) table — e.g. a
     * party wants to move seats, or the table needs to be freed for
     * cleaning while the party waits at a new one. Nothing about the order
     * itself changes, only which physical table it's attached to.
     */
    public function transfer(Request $request, RestaurantTable $restaurantTable)
    {
        $data = $request->validate([
            'to_table_id' => ['required', 'exists:restaurant_tables,id'],
        ]);

        if ((int) $data['to_table_id'] === $restaurantTable->id) {
            abort(422, 'একই টেবিলে স্থানান্তর করা যাবে না।');
        }

        DB::transaction(function () use ($restaurantTable, $data) {
            // fetched (and therefore locked) in a fixed ascending-id order
            // regardless of which table initiated the request — otherwise
            // two simultaneous transfers touching the same pair of tables
            // in opposite request-order could deadlock each other
            $toTableId = (int) $data['to_table_id'];
            $locked = RestaurantTable::whereIn('id', [$restaurantTable->id, $toTableId])->lockForUpdate()->orderBy('id')->get()->keyBy('id');
            $fromLocked = $locked[$restaurantTable->id];
            $toLocked = $locked[$toTableId];

            $order = TableOrder::where('restaurant_table_id', $fromLocked->id)->where('status', 'open')->first();
            if (! $order) {
                abort(422, 'এই টেবিলে কোনো খোলা অর্ডার নেই।');
            }
            if ($toLocked->status !== 'free') {
                abort(422, "'{$toLocked->name}' টেবিলটি খালি নেই।");
            }

            $order->update(['restaurant_table_id' => $toLocked->id]);
            $fromLocked->update(['status' => 'free']);
            $toLocked->update(['status' => 'occupied']);

            Activity::log('restaurant.transfer', "অর্ডার '{$fromLocked->name}' থেকে '{$toLocked->name}'-এ স্থানান্তর করা হয়েছে।", $order);
        });

        return back()->with('success', 'টেবিল পরিবর্তন করা হয়েছে।');
    }

    /**
     * Folds another occupied table's whole open order into this one —
     * e.g. two tables turn out to be the same party. Every still-open line
     * (billed/split-away lines have nothing left to move) is re-parented
     * onto this table's order; the other table's order is marked 'merged'
     * (not 'cancelled' — no stock was reversed, its items just live
     * somewhere else now) and its table freed.
     */
    public function merge(Request $request, RestaurantTable $restaurantTable)
    {
        $data = $request->validate([
            'from_table_id' => ['required', 'exists:restaurant_tables,id'],
        ]);

        if ((int) $data['from_table_id'] === $restaurantTable->id) {
            abort(422, 'একই টেবিল একসাথে মার্জ করা যাবে না।');
        }

        DB::transaction(function () use ($restaurantTable, $data) {
            $ids = collect([$restaurantTable->id, (int) $data['from_table_id']])->sort()->values();
            $locked = RestaurantTable::whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get()->keyBy('id');
            $intoLocked = $locked[$restaurantTable->id];
            $fromLocked = $locked[(int) $data['from_table_id']];

            $intoOrder = TableOrder::where('restaurant_table_id', $intoLocked->id)->where('status', 'open')->first();
            $fromOrder = TableOrder::where('restaurant_table_id', $fromLocked->id)->where('status', 'open')->first();
            if (! $intoOrder || ! $fromOrder) {
                abort(422, 'দুটো টেবিলেই একটা করে খোলা অর্ডার থাকতে হবে।');
            }

            TableOrderItem::where('table_order_id', $fromOrder->id)
                ->whereNull('sale_id')
                ->update(['table_order_id' => $intoOrder->id]);

            $mergedNote = collect([$intoOrder->kitchen_note, $fromOrder->kitchen_note])->filter()->implode(' | ');
            if ($mergedNote !== '') {
                $intoOrder->update(['kitchen_note' => $mergedNote]);
            }

            $fromOrder->update(['status' => 'merged']);
            $fromLocked->update(['status' => 'free']);

            Activity::log('restaurant.merge', "টেবিল '{$fromLocked->name}'-এর অর্ডার '{$intoLocked->name}'-এ মার্জ করা হয়েছে।", $intoOrder);
        });

        return redirect()->route('app.restaurant.orders.show', TableOrder::where('restaurant_table_id', $restaurantTable->id)->where('status', 'open')->first()->id)
            ->with('success', 'অর্ডার মার্জ করা হয়েছে।');
    }
}
