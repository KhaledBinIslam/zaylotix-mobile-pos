<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A quantity-break wholesale price row — see docs/FIX_PLAN.md's Phase 30 design note, Part A. */
class ProductPriceTier extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'min_qty', 'price'];

    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
