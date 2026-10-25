<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One COD settlement event (one courier, several delivered orders settled together) — see docs/FIX_PLAN.md's Phase 33 design note. */
class CourierSettlement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'courier_id', 'expected_amount', 'received_amount', 'difference', 'note', 'settled_by', 'settled_at'];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function courier()
    {
        return $this->belongsTo(Courier::class);
    }

    public function settledByUser()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function orders()
    {
        return $this->hasMany(OnlineOrder::class);
    }
}
