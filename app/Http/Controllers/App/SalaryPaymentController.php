<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\Shop;
use App\Support\Activity;
use App\Support\Payroll;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SalaryPaymentController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->get('month', now()->format('Y-m'));
        $employees = Employee::where('status', 'active')->orderBy('name')->get();

        $rows = $employees->map(function (Employee $e) use ($month) {
            $paid = SalaryPayment::where('employee_id', $e->id)->where('month', $month)->first();
            $outstandingAdvance = (float) $e->salaryAdvances()->sum('outstanding');

            return [
                'id' => $e->id,
                'name' => $e->name,
                'designation' => $e->designation,
                'salary_type' => $e->salary_type,
                'basic_salary' => (float) $e->basic_salary,
                'outstanding_advance' => $outstandingAdvance,
                'paid' => $paid ? [
                    'net_paid' => (float) $paid->net_paid,
                    'method' => $paid->method,
                    'paid_date' => $paid->paid_date->toDateString(),
                ] : null,
                'preview' => $paid ? null : Payroll::preview($e, $month),
            ];
        });

        return Inertia::render('App/Employees/Payroll', [
            'month' => $month,
            'rows' => $rows,
            'totalPaidThisMonth' => (float) SalaryPayment::where('month', $month)->sum('net_paid'),
        ]);
    }

    public function store(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'bonus' => ['nullable', 'numeric', 'min:0'],
            'advance_deduction' => ['nullable', 'numeric', 'min:0'],
            'net_paid' => ['required', 'numeric', 'min:0'],
            'method' => ['required', 'in:cash,bank'],
        ]);

        if (SalaryPayment::where('employee_id', $employee->id)->where('month', $data['month'])->exists()) {
            throw ValidationException::withMessages(['month' => 'এই মাসের বেতন ইতিমধ্যে দেওয়া হয়েছে।']);
        }

        $preview = Payroll::preview($employee, $data['month']);
        $outstandingAdvance = (float) $employee->salaryAdvances()->sum('outstanding');
        $advanceDeduction = min((float) ($data['advance_deduction'] ?? 0), $outstandingAdvance);
        $netPaid = (float) $data['net_paid'];

        DB::transaction(function () use ($employee, $data, $preview, $advanceDeduction, $netPaid) {
            $shop = Shop::whereKey(Tenancy::id())->lockForUpdate()->first();
            $field = $data['method'] === 'cash' ? 'cash_balance' : 'bank_balance';
            $shop->decrement($field, $netPaid);

            SalaryPayment::create([
                'employee_id' => $employee->id,
                'month' => $data['month'],
                'basic_salary' => $employee->basic_salary,
                'present_days' => $preview['present_days'],
                'absent_days' => $preview['absent_days'],
                'attendance_deduction' => $preview['attendance_deduction'],
                'bonus' => $data['bonus'] ?? 0,
                'advance_deduction' => $advanceDeduction,
                'net_paid' => $netPaid,
                'method' => $data['method'],
                'paid_date' => now()->toDateString(),
            ]);

            // settle outstanding advances oldest-first up to advanceDeduction
            $remaining = $advanceDeduction;
            foreach ($employee->salaryAdvances()->where('outstanding', '>', 0)->orderBy('date')->get() as $advance) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, (float) $advance->outstanding);
                $advance->decrement('outstanding', $take);
                $remaining -= $take;
            }

            Activity::log('employee.salaryPaid', "'{$employee->name}'-কে {$data['month']} মাসের বেতন ".number_format($netPaid, 2)." টাকা পরিশোধ করা হয়েছে।", $employee, ['net_paid' => $netPaid]);
        });

        return back()->with('success', 'Salary paid.');
    }
}
