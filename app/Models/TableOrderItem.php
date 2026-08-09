<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TableOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'table_order_id', 'product_id', 'product_name', 'qty', 'price', 'discount', 'cost', 'sale_id', 'kot_printed_at', 'cooked_at', 'served_at'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
            'cost' => 'decimal:2',
            'kot_printed_at' => 'datetime',
            'cooked_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(TableOrder::class, 'table_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Set once this specific line has been billed — into which sale (a table order can be split across several). */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
