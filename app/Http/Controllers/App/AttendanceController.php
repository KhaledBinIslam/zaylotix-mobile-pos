<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->get('date', now()->toDateString());
        $employees = Employee::where('status', 'active')->orderBy('name')->get();
        $attendances = Attendance::whereDate('date', $date)->get()->keyBy('employee_id');

        return Inertia::render('App/Employees/Attendance', [
            'date' => $date,
            'employees' => $employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'designation' => $e->designation,
                // no record yet for today defaults to 'present' — an owner
                // only needs to touch the ones who are actually absent/on
                // leave, not tap "present" for everyone every single day
                'status' => $attendances->get($e->id)?->status ?? 'present',
            ]),
        ]);
    }

    public function mark(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('shop_id', Tenancy::id())],
            'status' => ['required', 'in:present,absent,leave,half_day'],
        ]);

        Attendance::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            ['status' => $data['status']]
        );

        return back();
    }
}
