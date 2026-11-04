<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function index()
    {
        /*
         * Khaled: "keo joddhi running mase advance [nei] oder staf er
         * oikhane jate advance ta dekhai ... details e sob dekhai koi
         * tarik kon purpos advance niche" — the staff list answers who
         * owes what, advance by advance, not just a single total.
         *
         * Which advances count as worth showing: anything still unsettled
         * (whenever it was taken — money owed doesn't expire at month
         * end), plus anything taken THIS month even if already settled,
         * so the running month's story stays complete.
         */
        $advances = \App\Models\SalaryAdvance::where(fn ($q) => $q
            ->where('outstanding', '>', 0)
            ->orWhere('date', '>=', now()->startOfMonth()->toDateString()))
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('App/Employees/Index', [
            'employees' => Employee::orderBy('status')->orderBy('name')->get()
                ->map(fn (Employee $e) => array_merge($e->toArray(), [
                    'outstanding_advance' => (float) ($advances[$e->id] ?? collect())->sum('outstanding'),
                    'advances' => ($advances[$e->id] ?? collect())->map(fn ($a) => [
                        'date' => $a->date->format('d/m/Y'),
                        'amount' => (float) $a->amount,
                        'outstanding' => (float) $a->outstanding,
                        'note' => $a->note,
                        'method' => $a->method,
                    ])->values(),
                ]))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'designation' => ['nullable', 'string', 'max:255'],
            'salary_type' => ['required', 'in:monthly,daily'],
            'basic_salary' => ['required', 'numeric', 'min:0.01'],
            'joining_date' => ['required', 'date'],
        ]);

        Employee::create([...$data, 'status' => 'active']);

        return back()->with('success', 'Employee added.');
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'designation' => ['nullable', 'string', 'max:255'],
            'salary_type' => ['required', 'in:monthly,daily'],
            'basic_salary' => ['required', 'numeric', 'min:0.01'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $employee->update($data);

        return back()->with('success', 'Employee updated.');
    }
}
