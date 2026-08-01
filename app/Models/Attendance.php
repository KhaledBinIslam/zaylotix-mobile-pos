<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'employee_id', 'date', 'status'];

    protected function casts(): array
    {
        return [
            // explicit format (not plain 'date') — updateOrCreate() searches
            // this column by exact string equality (see AttendanceController),
            // and the default cast serializes with a time component that
            // silently fails to match a plain 'Y-m-d' search value, causing
            // a duplicate-insert attempt into the (employee_id, date) unique
            // constraint instead of updating the existing row.
            'date' => 'date:Y-m-d',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
