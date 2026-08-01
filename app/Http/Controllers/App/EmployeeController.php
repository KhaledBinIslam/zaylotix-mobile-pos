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
        return Inertia::render('App/Employees/Index', [
            'employees' => Employee::orderBy('status')->orderBy('name')->get(),
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
