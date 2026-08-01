<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    /** Collect a due payment from a customer; moves cash into the shop's balance atomically. */
    public function store(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'in:cash,bkash,nagad'],
        ]);

        $amount = (float) $data['amount'];

        DB::transaction(function () use ($customer, $amount, $data) {
            // Customer::whereKey(...)->lockForUpdate() — NOT
            // $customer->lockForUpdate() — because lockForUpdate() isn't a
            // real Model method; calling it on an already-hydrated instance
            // forwards to a fresh query with no primary-key WHERE clause,
            // silently locking (and then updating) every customer row for
            // the shop instead of just this one.
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();

            // Re-check against the row we just locked, not the pre-request
            // value on $customer — two payments submitted at nearly the same
            // moment must not both pass a check against the same stale due.
            if ($amount > (float) $locked->due) {
                throw ValidationException::withMessages([
                    'amount' => "এত বেশি বাকি নেই। {$locked->name}-এর সর্বোচ্চ বাকি: ".number_format((float) $locked->due, 2),
                ]);
            }

            $locked->decrement('due', $amount);

            $locked->payments()->create([
                'shop_id' => Tenancy::id(),
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'amount' => $amount,
                'method' => $data['method'] ?? 'cash',
                'date' => now()->toDateString(),
            ]);

            if (($data['method'] ?? 'cash') === 'cash') {
                \App\Models\Shop::whereKey(Tenancy::id())->increment('cash_balance', $amount);
            } else {
                \App\Models\Shop::whereKey(Tenancy::id())->increment('bank_balance', $amount);
            }
        });

        return back()->with('success', 'Payment recorded.');
    }

    public function full(Customer $customer)
    {
        $this->authorize('update', $customer);

        DB::transaction(function () use ($customer) {
            $locked = Customer::whereKey($customer->id)->lockForUpdate()->first();
            $amount = (float) $locked->due;
            $locked->update(['due' => 0]);

            $locked->payments()->create([
                'shop_id' => Tenancy::id(),
                'user_id' => Auth::guard('web')->id() ?? Auth::guard('sanctum')->id(),
                'amount' => $amount,
                'method' => 'cash',
                'date' => now()->toDateString(),
            ]);

            \App\Models\Shop::whereKey(Tenancy::id())->increment('cash_balance', $amount);
        });

        return back()->with('success', 'Marked fully paid.');
    }
}
