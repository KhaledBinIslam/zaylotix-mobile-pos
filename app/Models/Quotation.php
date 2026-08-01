<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'customer_id', 'customer_name', 'customer_phone',
        'quote_no', 'date', 'valid_until', 'status', 'subtotal', 'discount', 'total', 'notes', 'sale_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
