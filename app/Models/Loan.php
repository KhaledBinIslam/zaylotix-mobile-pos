<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'party_name', 'phone', 'type', 'principal', 'outstanding', 'method', 'note', 'date'];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'outstanding' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class);
    }
}
