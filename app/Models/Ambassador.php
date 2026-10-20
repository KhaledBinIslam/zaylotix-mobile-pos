<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ambassador extends Model
{
    // Not tenant-scoped — ambassadors are a platform-level, super-admin-only
    // concept with no relation to any shop's own tenant boundary.
    protected $fillable = ['name', 'employee_id', 'referral_code', 'phone', 'email', 'note', 'one_time_bonus', 'recurring_percent', 'status'];

    protected function casts(): array
    {
        return [
            'one_time_bonus' => 'decimal:2',
            'recurring_percent' => 'decimal:2',
        ];
    }

    /** The public self-signup link that credits this ambassador automatically — see docs/FIX_PLAN.md's "অ্যাম্বাসেডর টুলস" design note. */
    public function signupUrl(): ?string
    {
        return $this->referral_code ? route('signup').'?ref='.$this->referral_code : null;
    }

    public function commissions()
    {
        return $this->hasMany(AmbassadorCommission::class);
    }

    public function assignments()
    {
        return $this->hasMany(AmbassadorShopAssignment::class);
    }

    /** Shops where this ambassador is the one credited with bringing them in — immutable in normal use, drives the one-time bonus. */
    public function broughtShops()
    {
        return $this->hasMany(Shop::class, 'brought_by_ambassador_id');
    }

    /** Shops this ambassador is CURRENTLY responsible for (drives recurring %) — may differ from broughtShops() if an account was reassigned. */
    public function currentShops()
    {
        return $this->belongsToMany(Shop::class, 'ambassador_shop_assignments')
            ->wherePivotNull('unassigned_at');
    }
}
