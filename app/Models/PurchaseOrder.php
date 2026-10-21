<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'supplier_id', 'po_number', 'date', 'expected_date', 'status', 'subtotal', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
