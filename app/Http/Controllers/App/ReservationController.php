<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableOrder;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        // defaults to today onward — a cashier opening this screen wants
        // "who's coming", not a lifetime history of every past reservation;
        // picking an explicit date (including a past one) still works
        $date = $request->get('date');

        return Inertia::render('App/Reservations/Index', [
            'date' => $date,
            'reservations' => Reservation::with('table:id,name')
                ->when($date, fn ($q) => $q->whereDate('reservation_at', $date))
                ->when(! $date, fn ($q) => $q->whereDate('reservation_at', '>=', now()->toDateString()))
                ->orderBy('reservation_at')
                ->limit(100)
                ->get(),
            'freeTables' => RestaurantTable::where('status', 'free')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'reservation_at' => ['required', 'date'],
            'restaurant_table_id' => ['nullable', 'exists:restaurant_tables,id'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'advance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $reservation = Reservation::create($data + ['advance' => $data['advance'] ?? 0, 'status' => 'reserved']);
        Activity::log('reservation.created', "রিজার্ভেশন তৈরি — {$reservation->name} ({$reservation->guest_count} জন)");

        return back()->with('success', 'রিজার্ভেশন তৈরি হয়েছে।');
    }

    public function update(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'reservation_at' => ['required', 'date'],
            'restaurant_table_id' => ['nullable', 'exists:restaurant_tables,id'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'advance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $reservation->update($data + ['advance' => $data['advance'] ?? 0]);

        return back()->with('success', 'রিজার্ভেশন আপডেট হয়েছে।');
    }

    public function cancel(Reservation $reservation)
    {
        $reservation->update(['status' => 'cancelled']);
        Activity::log('reservation.cancelled', "রিজার্ভেশন বাতিল — {$reservation->name}");

        return back()->with('success', 'রিজার্ভেশন বাতিল করা হয়েছে।');
    }

    public function noShow(Reservation $reservation)
    {
        $reservation->update(['status' => 'no_show']);

        return back()->with('success', 'নো-শো হিসেবে চিহ্নিত করা হয়েছে।');
    }

    /**
     * Guest has arrived — if a table was pre-linked, this opens it exactly
     * like RestaurantTableController::open() does (same lock-then-recheck
     * pattern, so a table already opened by someone else in the meantime
     * is never double-opened) and lands the cashier straight on the order.
     * With no table linked, this just marks the reservation seated; the
     * cashier opens a table separately from the Tables screen.
     */
    public function seat(Reservation $reservation)
    {
        if (! $reservation->restaurant_table_id) {
            $reservation->update(['status' => 'seated']);

            return back()->with('success', 'সিটেড হিসেবে চিহ্নিত করা হয়েছে।');
        }

        $order = DB::transaction(function () use ($reservation) {
            $lockedTable = RestaurantTable::whereKey($reservation->restaurant_table_id)->lockForUpdate()->first();

            if ($lockedTable->status === 'occupied') {
                $existing = TableOrder::where('restaurant_table_id', $lockedTable->id)->where('status', 'open')->first();
                if ($existing) {
                    $reservation->update(['status' => 'seated']);

                    return $existing;
                }
            }

            $order = TableOrder::create([
                'restaurant_table_id' => $lockedTable->id,
                'status' => 'open',
                'opened_at' => now(),
            ]);
            $lockedTable->update(['status' => 'occupied']);
            $reservation->update(['status' => 'seated']);

            return $order;
        });

        return redirect()->route('app.restaurant.orders.show', $order->id);
    }
}
