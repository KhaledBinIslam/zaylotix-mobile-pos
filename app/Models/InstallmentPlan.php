<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InstallmentPlan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'customer_id', 'sale_id', 'total_amount', 'down_payment',
        'installment_count', 'frequency', 'start_date', 'late_fee_type', 'late_fee_amount',
        'status', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'late_fee_amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(InstallmentScheduleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** Scheduled total across every installment (excludes the down payment, already collected at sale time). */
    public function scheduledTotal(): float
    {
        return (float) $this->items->sum('amount');
    }

    public function totalPaid(): float
    {
        return (float) $this->items->sum('amount_paid');
    }

    public function totalLateFees(): float
    {
        return (float) $this->items->sum('late_fee_applied');
    }

    public function remainingBalance(): float
    {
        return max(0, $this->scheduledTotal() + $this->totalLateFees() - $this->totalPaid());
    }

    public function hasOverdueItems(): bool
    {
        return $this->items->contains(fn (InstallmentScheduleItem $i) => $i->isOverdue());
    }
}
