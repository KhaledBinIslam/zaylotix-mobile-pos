<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'date', 'changed', 'changes'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'changes' => 'array',
        ];
    }
}
