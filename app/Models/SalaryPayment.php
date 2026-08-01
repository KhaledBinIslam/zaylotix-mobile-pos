<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalaryPayment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'employee_id', 'month', 'basic_salary', 'present_days', 'absent_days',
        'attendance_deduction', 'bonus', 'advance_deduction', 'net_paid', 'method', 'paid_date',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'attendance_deduction' => 'decimal:2',
            'bonus' => 'decimal:2',
            'advance_deduction' => 'decimal:2',
            'net_paid' => 'decimal:2',
            'paid_date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
