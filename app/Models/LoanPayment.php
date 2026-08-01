<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LoanPayment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'loan_id', 'amount', 'method', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
