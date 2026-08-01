<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;

/**
 * Salary computation for one employee/month — pulled out of the controller
 * so both the payroll preview (GET) and the actual payment (POST) call the
 * exact same math, and so it's unit-testable without spinning up a request.
 *
 * 'monthly' salary_type: a fixed basic_salary regardless of days in the
 * month, with absences deducted at a per-day share (basic / days-in-month).
 * 'daily' salary_type: basic_salary IS the per-day rate — only days actually
 * marked present/half-day are paid at all, nothing is assumed.
 */
class Payroll
{
    public static function preview(Employee $employee, string $month): array
    {
        [$year, $mon] = explode('-', $month);
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int) $mon, (int) $year);
        $start = "{$month}-01";
        $end = date('Y-m-t', strtotime($start));

        $attendances = Attendance::where('employee_id', $employee->id)->whereBetween('date', [$start, $end])->get();
        $absentDays = $attendances->where('status', 'absent')->count() + $attendances->where('status', 'leave')->count();
        $halfDays = $attendances->where('status', 'half_day')->count();
        $presentMarked = $attendances->where('status', 'present')->count();

        $basic = (float) $employee->basic_salary;

        if ($employee->salary_type === 'monthly') {
            $dailyRate = $daysInMonth > 0 ? $basic / $daysInMonth : 0;
            $deduction = round($dailyRate * ($absentDays + $halfDays * 0.5), 2);
            $presentDays = $daysInMonth - $absentDays - ($halfDays * 0.5);

            return [
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'attendance_deduction' => $deduction,
                'suggested_net' => round($basic - $deduction, 2),
            ];
        }

        $presentDays = $presentMarked + $halfDays * 0.5;

        return [
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'attendance_deduction' => 0.0,
            'suggested_net' => round($basic * $presentDays, 2),
        ];
    }
}
