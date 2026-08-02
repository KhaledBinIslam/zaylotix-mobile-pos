<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'unit', 'stock', 'cost', 'reorder_point'];

    protected function casts(): array
    {
        return [
            'stock' => 'decimal:3',
            'cost' => 'decimal:2',
            'reorder_point' => 'decimal:3',
        ];
    }

    public function recipes()
    {
        return $this->hasMany(ProductRecipe::class);
    }
}
