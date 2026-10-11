<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The commission ledger — one row per one-time bonus or per recurring payment's cut. See AmbassadorCommissionService for how rows get created. */
class AmbassadorCommission extends Model
{
    protected $fillable = [
        'ambassador_id', 'shop_id', 'type', 'amount', 'month',
        'subscription_payment_id', 'status', 'paid_at', 'paid_by_admin_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function ambassador()
    {
        return $this->belongsTo(Ambassador::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function subscriptionPayment()
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(Admin::class, 'paid_by_admin_id');
    }
}
