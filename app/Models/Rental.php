<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Rental extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'customer_id', 'invoice_no', 'rental_date', 'expected_return_date',
        'actual_return_date', 'status', 'total_rent_amount', 'security_deposit',
        'notes', 'user_id', 'voided_at', 'client_uuid',
    ];

    protected function casts(): array
    {
        return [
            'rental_date' => 'date:Y-m-d',
            'expected_return_date' => 'date:Y-m-d',
            'actual_return_date' => 'date:Y-m-d',
            'total_rent_amount' => 'decimal:2',
            'security_deposit' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(RentalItem::class);
    }

    public function returns()
    {
        return $this->hasMany(RentalReturn::class);
    }

    /** Money actually collected against this rental, through the shared Payment ledger — never a second, parallel total. */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** What this rental has charged in total so far: the original amount plus every late fee/damage charge applied on return. */
    public function totalCharged(): float
    {
        return (float) $this->total_rent_amount + (float) $this->returns->sum('late_fee') + (float) $this->returns->sum('damage_charge');
    }

    public function totalPaid(): float
    {
        return (float) $this->payments->sum('amount');
    }

    public function remainingDue(): float
    {
        return max(0, $this->totalCharged() - $this->totalPaid());
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['active', 'partially_returned'], true)
            && $this->expected_return_date->isPast();
    }
}
