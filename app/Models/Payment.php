<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'customer_id', 'user_id', 'amount', 'due_before', 'due_after', 'method', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_before' => 'decimal:2',
            'due_after' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
