<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HeldCart extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'label', 'cart_data'];

    protected function casts(): array
    {
        return [
            'cart_data' => 'array',
        ];
    }
}
