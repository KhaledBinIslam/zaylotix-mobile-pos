<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'status'];

    public function orders()
    {
        return $this->hasMany(TableOrder::class);
    }

    /** The single open order for this table, if it's currently occupied. */
    public function openOrder()
    {
        return $this->hasOne(TableOrder::class)->where('status', 'open');
    }
}
