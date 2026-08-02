<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductRecipe extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'product_id', 'ingredient_id', 'qty_per_unit'];

    protected function casts(): array
    {
        return [
            'qty_per_unit' => 'decimal:3',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
