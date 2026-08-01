<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['shop_id', 'product_id', 'size', 'color', 'barcode', 'stock', 'price', 'cost'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function effectivePrice(): float
    {
        return (float) ($this->price ?? $this->product->price);
    }

    public function effectiveCost(): float
    {
        return (float) ($this->cost ?? $this->product->cost);
    }

    public function label(): string
    {
        return collect([$this->size, $this->color])->filter()->implode(', ');
    }
}
