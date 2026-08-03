<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'supplier', 'supplier_id', 'memo', 'amount', 'method', 'status', 'pending_details', 'date', 'product_id', 'product_variant_id', 'ingredient_id', 'qty'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'pending_details' => 'array',
        ];
    }

    public function supplierModel()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
