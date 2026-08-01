<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'name', 'type', 'active',
        'code', 'discount_type', 'discount_value', 'min_purchase', 'usage_limit',
        'buy_product_id', 'buy_qty', 'get_product_id', 'get_qty', 'get_discount_percent',
        'used_count', 'starts_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'discount_value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'get_discount_percent' => 'decimal:2',
            'starts_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    public function buyProduct()
    {
        return $this->belongsTo(Product::class, 'buy_product_id');
    }

    public function getProduct()
    {
        return $this->belongsTo(Product::class, 'get_product_id');
    }

    public function isWithinDateWindow(): bool
    {
        $today = now()->toDateString();
        if ($this->starts_at && $this->starts_at->toDateString() > $today) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->toDateString() < $today) {
            return false;
        }

        return true;
    }
}
