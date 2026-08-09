<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TableOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'restaurant_table_id', 'status', 'customer_name', 'sale_id', 'opened_at', 'order_source', 'delivery_platform', 'kitchen_note', 'waiter_name'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /** Every item ever added to this order, billed or not — callers that want just the still-open tab should eager-load with a `whereNull('sale_id')` constraint (see TableOrderController::show/RestaurantTableController::index). */
    public function items()
    {
        return $this->hasMany(TableOrderItem::class);
    }

    /** The one sale that finally closed this order (kept for backward compatibility) — for a split-billed order, use sales() to see every sale it produced. */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    /** Every sale this order has produced — one for a plain full bill, several for a split bill. */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * The running total of whatever's still unbilled — assumes `items` was
     * eager-loaded already scoped to `whereNull('sale_id')` (the normal case
     * for every "current state of this table" view). Summing the raw
     * relation here would double as "lifetime order value" instead once a
     * split bill exists, which no caller actually wants.
     */
    public function total(): float
    {
        return (float) $this->items->sum(fn (TableOrderItem $i) => $i->price * $i->qty - min((float) $i->discount, $i->price * $i->qty));
    }
}
