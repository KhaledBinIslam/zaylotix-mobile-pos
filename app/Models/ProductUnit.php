<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'unit_id', 'factor', 'price'];

    protected function casts(): array
    {
        return [
            'factor' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
