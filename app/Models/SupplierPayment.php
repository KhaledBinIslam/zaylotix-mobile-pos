<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A structured settlement row against Supplier.payable — mirrors Payment (the customer-due equivalent) exactly. */
class SupplierPayment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'supplier_id', 'user_id', 'amount', 'payable_before', 'payable_after', 'method', 'date'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payable_before' => 'decimal:2',
            'payable_after' => 'decimal:2',
            'date' => 'date:Y-m-d',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
