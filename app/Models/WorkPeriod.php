<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WorkPeriod extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'opened_by', 'opened_at', 'opening_cash', 'cash_balance_at_open',
        'closed_at', 'closing_cash', 'cash_balance_at_close', 'variance',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'cash_balance_at_open' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'cash_balance_at_close' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    public function openedByUser()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
