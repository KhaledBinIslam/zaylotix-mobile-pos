<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreCourierRequest;
use App\Models\Courier;
use App\Models\CourierSettlement;
use App\Models\OnlineOrder;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** "Online Business" module, part 3 — see docs/FIX_PLAN.md's Phase 33 design note. */
class CourierController extends Controller
{
    public function index()
    {
        return Inertia::render('App/OnlineBusiness/Couriers/Index', [
            'couriers' => Courier::orderBy('name')->get(),
        ]);
    }

    public function store(StoreCourierRequest $request)
    {
        $data = $request->validated();

        Courier::create([
            'name' => $data['name'],
            'charge_type' => $data['charge_type'],
            'charge_config' => $data['charge_config'] ?? [],
            'cod_fee_percent' => $data['cod_fee_percent'] ?? null,
            'return_charge' => $data['return_charge'] ?? null,
            'active' => $data['active'] ?? true,
        ]);

        return back()->with('success', "{$data['name']} কুরিয়ার যোগ করা হয়েছে।");
    }

    public function update(StoreCourierRequest $request, Courier $courier)
    {
        $this->authorize('update', $courier);

        $data = $request->validated();

        $courier->update([
            'name' => $data['name'],
            'charge_type' => $data['charge_type'],
            'charge_config' => $data['charge_config'] ?? [],
            'cod_fee_percent' => $data['cod_fee_percent'] ?? null,
            'return_charge' => $data['return_charge'] ?? null,
            'active' => $data['active'] ?? $courier->active,
        ]);

        return back()->with('success', "{$courier->name} আপডেট করা হয়েছে।");
    }

    public function destroy(Courier $courier)
    {
        $this->authorize('delete', $courier);
        $courier->delete();

        return back()->with('success', 'কুরিয়ার মুছে ফেলা হয়েছে।');
    }

    /**
     * Analytics + the COD settlement screen for one courier — parcels
     * handled, delivered/returned/failed %, avg delivery time, COD
     * collected vs. pending, charges accrued, net settlement due. See
     * docs/FIX_PLAN.md's Phase 33 design note.
     */
    public function show(Courier $courier)
    {
        $this->authorize('view', $courier);

        $orders = OnlineOrder::where('courier_id', $courier->id)->get();
        $total = $orders->count();
        $delivered = $orders->where('status', 'delivered');
        $returned = $orders->whereIn('status', ['returned', 'returning']);
        $failed = $orders->where('status', 'failed');

        $avgDeliveryHours = $delivered->filter(fn ($o) => $o->delivered_at)
            ->map(fn ($o) => $o->created_at->diffInHours($o->delivered_at))
            ->avg();

        $pendingCod = OnlineOrder::where('courier_id', $courier->id)
            ->where('status', 'delivered')->whereNull('cod_settled_at')
            ->get(['id', 'order_number', 'cod_amount', 'delivered_at'])
            ->map(fn ($o) => ['id' => $o->id, 'order_number' => $o->order_number, 'cod_amount' => (float) $o->cod_amount, 'delivered_at' => $o->delivered_at]);

        return Inertia::render('App/OnlineBusiness/Couriers/Show', [
            'courier' => $courier,
            'stats' => [
                'total' => $total,
                'delivered' => $delivered->count(),
                'returned' => $returned->count(),
                'failed' => $failed->count(),
                'delivered_pct' => $total ? round($delivered->count() / $total * 100, 1) : 0,
                'returned_pct' => $total ? round($returned->count() / $total * 100, 1) : 0,
                'failed_pct' => $total ? round($failed->count() / $total * 100, 1) : 0,
                'avg_delivery_hours' => $avgDeliveryHours ? round($avgDeliveryHours, 1) : null,
                'cod_collected' => (float) OnlineOrder::where('courier_id', $courier->id)->whereNotNull('cod_settled_at')->sum('cod_amount'),
                'cod_pending' => (float) $pendingCod->sum('cod_amount'),
                'charges_accrued' => (float) $orders->sum('courier_charge'),
            ],
            'pendingCodOrders' => $pendingCod,
            'settlements' => CourierSettlement::where('courier_id', $courier->id)->orderByDesc('settled_at')->with('settledByUser:id,name')->get(),
        ]);
    }

    /**
     * COD settlement — the owner picks which delivered-and-unsettled
     * orders the courier actually paid out for, types what was really
     * received, and the difference (if any) is shown, never hidden.
     * Settling is the one point COD money becomes real shop cash.
     */
    public function settle(Request $request, Courier $courier)
    {
        $this->authorize('update', $courier);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
            'received_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // settlement screen is a plain router.post (no useForm), so a
        // rejection must go through the flash.error toast path, never a
        // bare abort() — see docs/FIX_PLAN.md's Phase 32 live-bug note
        $settleableCount = OnlineOrder::whereIn('id', $data['order_ids'])
            ->where('courier_id', $courier->id)->where('status', 'delivered')->whereNull('cod_settled_at')->count();
        if ($settleableCount === 0) {
            return back()->with('error', 'সেটেল করার মতো কোনো অর্ডার পাওয়া যায়নি।');
        }

        DB::transaction(function () use ($data, $courier) {
            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();

            $orders = OnlineOrder::whereIn('id', $data['order_ids'])
                ->where('courier_id', $courier->id)
                ->where('status', 'delivered')
                ->whereNull('cod_settled_at')
                ->lockForUpdate()
                ->get();

            $expected = (float) $orders->sum('cod_amount');
            $received = (float) $data['received_amount'];

            $settlement = CourierSettlement::create([
                'shop_id' => $shop->id,
                'courier_id' => $courier->id,
                'expected_amount' => $expected,
                'received_amount' => $received,
                'difference' => $received - $expected,
                'note' => $data['note'] ?? null,
                'settled_by' => Auth::guard('web')->id(),
                'settled_at' => now(),
            ]);

            OnlineOrder::whereIn('id', $orders->pluck('id'))->update([
                'cod_settled_at' => now(),
                'courier_settlement_id' => $settlement->id,
            ]);

            // the settlement's own received_amount — not the expected
            // total — is what actually reached the shop; a shortfall/
            // overage is real and stays visible on the settlement record
            $shop->cash_balance += $received;
            $shop->save();
        });

        return back()->with('success', 'COD সেটেলমেন্ট সম্পন্ন হয়েছে।');
    }
}
