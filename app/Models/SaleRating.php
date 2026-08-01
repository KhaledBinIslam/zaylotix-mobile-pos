<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SaleRating extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'sale_id', 'stars', 'comment'];

    protected function casts(): array
    {
        return [
            'stars' => 'integer',
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
