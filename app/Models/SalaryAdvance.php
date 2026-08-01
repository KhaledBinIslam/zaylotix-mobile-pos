<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalaryAdvance extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'employee_id', 'amount', 'outstanding', 'method', 'note', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'outstanding' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
