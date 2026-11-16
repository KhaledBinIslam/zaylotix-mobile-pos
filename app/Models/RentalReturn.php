<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RentalReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'rental_id', 'rental_item_id', 'qty', 'condition',
        'late_fee', 'damage_charge', 'date', 'user_id', 'notes', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'late_fee' => 'decimal:2',
            'damage_charge' => 'decimal:2',
            'date' => 'date:Y-m-d',
        ];
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function item()
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
