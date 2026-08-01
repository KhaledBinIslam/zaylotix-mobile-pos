<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class LoanController extends Controller
{
    public function index()
    {
        $loans = Loan::with('payments')->latest('date')->latest('id')->paginate(30)->withQueryString();

        return Inertia::render('App/Loans/Index', [
            'loans' => $loans,
            'givenOutstanding' => (float) Loan::where('type', 'given')->sum('outstanding'),
            'takenOutstanding' => (float) Loan::where('type', 'taken')->sum('outstanding'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'party_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'in:given,taken'],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data) {
            $amount = (float) $data['principal'];
            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';

            // A loan GIVEN sends cash/bank out of the shop (an outflow that
            // becomes a receivable asset); a loan TAKEN brings money in (an
            // inflow that becomes a payable liability) — mirror image of
            // how Customer.due / Supplier.payable already work.
            if ($data['type'] === 'given') {
                $shop->decrement($field, $amount);
            } else {
                $shop->increment($field, $amount);
            }

            $loan = Loan::create([
                'party_name' => $data['party_name'],
                'phone' => $data['phone'] ?? null,
                'type' => $data['type'],
                'principal' => $amount,
                'outstanding' => $amount,
                'method' => $data['method'],
                'note' => $data['note'] ?? null,
                'date' => now()->toDateString(),
            ]);

            $verb = $data['type'] === 'given' ? 'কে দেওয়া হয়েছে' : 'থেকে নেওয়া হয়েছে';
            Activity::log('loan.create', "'{$loan->party_name}' {$verb} ".number_format($amount, 2)." টাকা ঋণ।", $loan, ['amount' => $amount, 'type' => $data['type']]);
        });

        return back()->with('success', 'Loan recorded.');
    }
}
