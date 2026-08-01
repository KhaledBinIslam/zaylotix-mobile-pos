<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'restaurant_table_id', 'name', 'phone', 'reservation_at',
        'guest_count', 'note', 'advance', 'status',
    ];

    protected function casts(): array
    {
        return [
            'reservation_at' => 'datetime',
            'advance' => 'decimal:2',
        ];
    }

    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }
}
