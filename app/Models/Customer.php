<?php

namespace App\Models;

use App\Models\Scopes\BusinessScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deliberately does NOT use BelongsToTenant — a customer is shared across
 * every branch of one business (due/loyalty/visit history follow the
 * person, not which till they happened to be standing at), so this uses
 * BusinessScope instead of the usual exact-shop_id TenantScope. `shop_id`
 * still gets auto-stamped on create (same as BelongsToTenant always did) —
 * it just means "where this customer was first seen", not "which shop owns
 * them"; every query still only ever sees customers within the acting
 * shop's own business (itself, for a shop with no branches — identical to
 * TenantScope there, so nothing changes for the vast majority of shops).
 */
class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = ['shop_id', 'name', 'phone', 'due', 'advance_balance', 'credit_limit', 'total_spent', 'visits', 'loyalty_points', 'no_marketing', 'is_wholesale', 'referred_by_customer_id', 'referral_rewarded_at'];

    protected static function booted(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function (Customer $model) {
            if (empty($model->shop_id)) {
                $model->shop_id = Tenancy::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due' => 'decimal:2',
            'advance_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'no_marketing' => 'boolean',
            'is_wholesale' => 'boolean',
            'referral_rewarded_at' => 'datetime',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** This customer's own override, or the shop-wide default (either may be null = no limit) — see docs/FIX_PLAN.md's "ক্রেডিট লিমিট" design note. */
    public function effectiveCreditLimit(): ?float
    {
        if ($this->credit_limit !== null) {
            return (float) $this->credit_limit;
        }

        $default = $this->shop?->default_credit_limit;

        return $default !== null ? (float) $default : null;
    }

    public function referredBy()
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    public function referrals()
    {
        return $this->hasMany(Customer::class, 'referred_by_customer_id');
    }
}
