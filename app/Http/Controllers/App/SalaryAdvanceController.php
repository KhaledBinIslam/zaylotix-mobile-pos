<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** An advance given against future salary — reduces cash/bank now, settled later out of a salary payment (see SalaryPaymentController). */
class SalaryAdvanceController extends Controller
{
    public function store(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($employee, $data) {
            $amount = (float) $data['amount'];
            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
            $shop->decrement($field, $amount);

            $employee->salaryAdvances()->create([
                'amount' => $amount,
                'outstanding' => $amount,
                'method' => $data['method'],
                'note' => $data['note'] ?? null,
                'date' => now()->toDateString(),
            ]);

            Activity::log('employee.advance', "'{$employee->name}'-কে ".number_format($amount, 2)." টাকা অগ্রিম বেতন দেওয়া হয়েছে।", $employee, ['amount' => $amount]);
        });

        return back()->with('success', 'Advance recorded.');
    }
}
