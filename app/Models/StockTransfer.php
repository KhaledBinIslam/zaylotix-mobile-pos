<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'from_shop_id', 'to_shop_id', 'from_product_id', 'to_product_id', 'product_name', 'qty', 'user_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function fromShop()
    {
        return $this->belongsTo(Shop::class, 'from_shop_id');
    }

    public function toShop()
    {
        return $this->belongsTo(Shop::class, 'to_shop_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
