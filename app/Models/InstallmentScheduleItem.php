<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InstallmentScheduleItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'installment_plan_id', 'due_date', 'amount', 'late_fee_applied',
        'amount_paid', 'status', 'late_fee_charged_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'late_fee_applied' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'late_fee_charged_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->startOfDay()->lt(now()->startOfDay());
    }

    public function balance(): float
    {
        return max(0, (float) $this->amount + (float) $this->late_fee_applied - (float) $this->amount_paid);
    }
}
