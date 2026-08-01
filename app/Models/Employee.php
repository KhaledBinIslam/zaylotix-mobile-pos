<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'phone', 'designation', 'salary_type', 'basic_salary', 'joining_date', 'status'];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'joining_date' => 'date',
        ];
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function salaryAdvances()
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function salaryPayments()
    {
        return $this->hasMany(SalaryPayment::class);
    }
}
