<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per period an ambassador was "currently responsible" for a shop — see Ambassador::currentShops(). */
class AmbassadorShopAssignment extends Model
{
    protected $fillable = ['shop_id', 'ambassador_id', 'assigned_at', 'unassigned_at', 'assigned_by_admin_id', 'note'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function ambassador()
    {
        return $this->belongsTo(Ambassador::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(Admin::class, 'assigned_by_admin_id');
    }
}
