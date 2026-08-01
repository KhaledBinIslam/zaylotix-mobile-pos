<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records a repayment against a loan, shrinking its outstanding balance — mirrors SupplierPaymentController. */
class LoanPaymentController extends Controller
{
    public function store(Request $request, Loan $loan)
    {
        $this->authorize('update', $loan);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank'],
        ]);

        $amount = (float) $data['amount'];

        DB::transaction(function () use ($loan, $amount, $data) {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->first();

            if ($amount > (float) $locked->outstanding) {
                throw ValidationException::withMessages([
                    'amount' => "এত বেশি বকেয়া নেই। '{$locked->party_name}'-এর সর্বোচ্চ বকেয়া: ".number_format((float) $locked->outstanding, 2),
                ]);
            }

            $locked->decrement('outstanding', $amount);

            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';

            // A GIVEN loan being repaid brings money back in; a TAKEN loan
            // being repaid sends money back out — inverse of the original
            // disbursement direction in LoanController::store.
            if ($locked->type === 'given') {
                $shop->increment($field, $amount);
            } else {
                $shop->decrement($field, $amount);
            }

            $locked->payments()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'date' => now()->toDateString(),
            ]);

            Activity::log('loan.payment', "'{$locked->party_name}'-এর ঋণে ".number_format($amount, 2)." টাকা পরিশোধ হয়েছে।", $locked, ['amount' => $amount]);
        });

        return back()->with('success', 'Loan payment recorded.');
    }
}
