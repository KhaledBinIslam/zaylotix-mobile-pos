<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RentalItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'rental_id', 'product_id', 'product_variant_id',
        'qty', 'rent_price', 'returned_qty', 'status',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'rent_price' => 'decimal:2',
            'returned_qty' => 'decimal:2',
        ];
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function returns()
    {
        return $this->hasMany(RentalReturn::class);
    }

    public function outstandingQty(): float
    {
        return max(0, (float) $this->qty - (float) $this->returned_qty);
    }
}
