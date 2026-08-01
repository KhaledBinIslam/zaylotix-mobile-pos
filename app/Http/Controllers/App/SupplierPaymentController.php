<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Pays down a supplier's payable balance — mirrors PaymentController (customer due) exactly, money owed out instead of in. */
class SupplierPaymentController extends Controller
{
    public function store(Request $request, Supplier $supplier)
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'in:cash,bkash,nagad'],
        ]);

        $amount = (float) $data['amount'];

        DB::transaction(function () use ($supplier, $amount, $data) {
            // Supplier::whereKey()->lockForUpdate() — never $supplier->lockForUpdate().
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->first();

            if ($amount > (float) $locked->payable) {
                throw ValidationException::withMessages([
                    'amount' => "এত বেশি বকেয়া নেই। {$locked->name}-এর সর্বোচ্চ বকেয়া: ".number_format((float) $locked->payable, 2),
                ]);
            }

            $locked->decrement('payable', $amount);

            $field = ($data['method'] ?? 'cash') === 'cash' ? 'cash_balance' : 'bank_balance';
            Shop::whereKey(Tenancy::id())->decrement($field, $amount);

            Activity::log('supplier.payment', "সাপ্লায়ার '{$locked->name}'-কে ".number_format($amount, 2)." টাকা পরিশোধ করা হয়েছে।", $locked, [
                'amount' => $amount, 'method' => $data['method'] ?? 'cash',
            ]);
        });

        return back()->with('success', 'Supplier payment recorded.');
    }
}
