<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PreparationItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'preparation_id', 'ingredient_id', 'ingredient_name', 'qty_consumed'];

    protected function casts(): array
    {
        return [
            'qty_consumed' => 'decimal:3',
        ];
    }

    public function preparation()
    {
        return $this->belongsTo(Preparation::class);
    }
}
