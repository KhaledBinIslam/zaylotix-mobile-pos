<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Owner-defined courier — see docs/FIX_PLAN.md's Phase 33 design note. */
class Courier extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'name', 'charge_type', 'charge_config', 'cod_fee_percent', 'return_charge', 'active'];

    public const CHARGE_TYPES = ['flat', 'weight', 'zone'];

    protected function casts(): array
    {
        return [
            'charge_config' => 'array',
            'cod_fee_percent' => 'decimal:2',
            'return_charge' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function orders()
    {
        return $this->hasMany(OnlineOrder::class);
    }

    public function settlements()
    {
        return $this->hasMany(CourierSettlement::class);
    }
}
