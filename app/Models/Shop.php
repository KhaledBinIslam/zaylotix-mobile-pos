<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Shop extends Model
{
    // Shop itself is NOT tenant-scoped (it *is* the tenant) — access control
    // happens via ShopPolicy (owner can only see their own; admin sees all).
    protected $fillable = [
        'business_type_id', 'name', 'name_en', 'phone', 'kitchen_whatsapp', 'payment_timing', 'kitchen_print_order', 'area', 'owner_name', 'logo_path', 'receipt_footer',
        'sales_mode', 'hardware_scanner_enabled', 'lang', 'plan', 'monthly_fee', 'status',
        'subscription_start', 'subscription_expiry',
        'cash_balance', 'bank_balance', 'capital',
        'vat_mode', 'vat_rate', 'turnover_rate', 'invoice_counter',
        'loyalty_earn_rate', 'loyalty_point_value',
    ];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'hardware_scanner_enabled' => 'boolean',
            'subscription_start' => 'date',
            'subscription_expiry' => 'date',
            'cash_balance' => 'decimal:2',
            'bank_balance' => 'decimal:2',
            'capital' => 'decimal:2',
            'monthly_fee' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'turnover_rate' => 'decimal:2',
            'loyalty_earn_rate' => 'decimal:2',
            'loyalty_point_value' => 'decimal:2',
        ];
    }

    public function businessType()
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function owner()
    {
        return $this->hasOne(User::class)->where('role', 'owner');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function subscriptionPayments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'shop_features');
    }

    /**
     * The single source of truth for "can this shop use capability X" — used
     * both server-side (middleware, controllers) and to build the `features`
     * array shared to the frontend, so the same check governs API access and
     * UI visibility. Cached per-request since it's checked repeatedly.
     */
    public function hasFeature(string $key): bool
    {
        return $this->featureKeys()->contains($key);
    }

    public function featureKeys(): Collection
    {
        return $this->relationLoaded('features')
            ? $this->features->pluck('key')
            : $this->features()->pluck('key');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->subscription_expiry && $this->subscription_expiry->isPast()) {
            return false;
        }

        return true;
    }

    public function daysLeft(): ?int
    {
        if (! $this->subscription_expiry) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->subscription_expiry->startOfDay(), false);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null);
    }

    // Invoice numbers are assigned inside PosController::checkout using an
    // explicit lockForUpdate() select — not here — so concurrent checkouts
    // for the same shop can never be handed the same invoice number.
}
