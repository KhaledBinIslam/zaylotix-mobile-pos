<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'sale_id', 'product_id', 'product_variant_id', 'product_serial_id', 'product_name',
        'unit_label', 'variant_label', 'unit_factor', 'qty', 'price', 'discount', 'cost', 'batch_allocations',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
            'cost' => 'decimal:2',
            'batch_allocations' => 'array',
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }
}
