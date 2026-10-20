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
        'parent_shop_id', 'brought_by_ambassador_id', 'is_warehouse', 'business_type_id', 'name', 'name_en', 'phone', 'email', 'website', 'kitchen_whatsapp', 'payment_timing', 'kitchen_print_order', 'area', 'owner_name', 'support_contact_name', 'support_contact_phone', 'logo_path', 'receipt_footer', 'bin_no', 'trade_license_no', 'tin_no', 'memo_settings', 'label_settings', 'mobile_nav_items', 'offline_sync_mode',
        'sales_mode', 'hardware_scanner_enabled', 'lang', 'plan', 'account_type', 'monthly_fee', 'staff_limit', 'status',
        'subscription_start', 'subscription_expiry',
        'cash_balance', 'bank_balance', 'capital',
        'vat_mode', 'vat_rate', 'turnover_rate', 'invoice_counter', 'service_charge_rate',
        'loyalty_earn_rate', 'loyalty_point_value', 'referral_bonus_points', 'return_window_days', 'dead_stock_days', 'catalog_slug', 'default_credit_limit', 'onboarded_at',
    ];

    protected $appends = ['logo_url', 'business_type_slug'];

    /** Every key a shop's memo_settings/label_settings JSON blob can hold, and what a brand-new (or still-null) shop sees today -- see memoSettings()/labelSettings(). */
    public const MEMO_SETTINGS_DEFAULTS = [
        'show_email' => false,
        'show_website' => false,
        'show_bin' => true, // preserves the pre-existing "show BIN automatically once set" behavior
        'show_trade_license' => false,
        'show_tin' => false,
        'show_cashier' => false,
        'show_customer_phone' => false,
        'show_payment_method' => false,
        'show_tendered_change' => false,
        'show_vat_inclusive_note' => false,
        'show_exchange_policy' => false,
        'exchange_policy_text' => null,
        'paper_width' => '58mm', // '58mm' | '80mm' | 'a4' -- preserves the pre-existing fixed 58mm print size
    ];

    public const LABEL_SETTINGS_DEFAULTS = [
        'size' => '38x25', // '38x25' | '50x25' | '40x30' | 'custom'
        'custom_width_mm' => null,
        'custom_height_mm' => null,
        'per_row' => 2, // preserves the pre-existing 2-per-row grid
        'show_shop_name' => true,
        'show_product_name' => true,
        'show_variant' => true,
        'price_mode' => 'both', // 'both' | 'single' | 'none' -- preserves the pre-existing default
        'show_barcode_number' => true,
    ];

    /** Merges saved overrides onto MEMO_SETTINGS_DEFAULTS -- a null/partial memo_settings (every shop before this feature shipped) resolves to exactly today's printed memo, nothing more. */
    public function memoSettings(): array
    {
        return array_merge(self::MEMO_SETTINGS_DEFAULTS, $this->memo_settings ?? []);
    }

    public function labelSettings(): array
    {
        return array_merge(self::LABEL_SETTINGS_DEFAULTS, $this->label_settings ?? []);
    }

    /**
     * The mobile bottom nav's two flexible slots (between the always-fixed
     * Home/Sell/More) — see docs/FIX_PLAN.md Phase 8. Null (every shop
     * before this feature, or one that's never customized it) resolves to
     * exactly this default, which is the exact pair every shop already saw
     * hardcoded before this existed — zero visual change until an owner
     * actually picks something else. AppLayout.vue is the one place that
     * decides which keys are valid choices and silently skips a saved key
     * that no longer resolves to anything (a feature since turned off).
     */
    public const MOBILE_NAV_DEFAULT = ['stock', 'due'];

    public function mobileNavItems(): array
    {
        return $this->mobile_nav_items ?? self::MOBILE_NAV_DEFAULT;
    }

    protected function casts(): array
    {
        return [
            'hardware_scanner_enabled' => 'boolean',
            'is_warehouse' => 'boolean',
            'memo_settings' => 'array',
            'label_settings' => 'array',
            'mobile_nav_items' => 'array',
            'subscription_start' => 'date:Y-m-d',
            'subscription_expiry' => 'date:Y-m-d',
            'cash_balance' => 'decimal:2',
            'bank_balance' => 'decimal:2',
            'capital' => 'decimal:2',
            'monthly_fee' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'turnover_rate' => 'decimal:2',
            'loyalty_earn_rate' => 'decimal:2',
            'loyalty_point_value' => 'decimal:2',
            'default_credit_limit' => 'decimal:2',
            'onboarded_at' => 'datetime',
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

    /** Null for a main shop (every shop today, unchanged) -- set for a branch, pointing at its business's main shop. */
    public function parentShop()
    {
        return $this->belongsTo(Shop::class, 'parent_shop_id');
    }

    /** Sibling branches of this (main) shop -- empty unless branches were created under it. */
    public function branches()
    {
        return $this->hasMany(Shop::class, 'parent_shop_id');
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

    /** Credited with bringing this shop onto the platform (one-time bonus) — may differ from who's currently assigned (recurring %), see AmbassadorShopAssignment. */
    public function broughtByAmbassador()
    {
        return $this->belongsTo(Ambassador::class, 'brought_by_ambassador_id');
    }

    public function ambassadorAssignments()
    {
        return $this->hasMany(AmbassadorShopAssignment::class);
    }

    /** The ambassador currently responsible for this shop's recurring commission, if any. */
    public function currentAmbassador(): ?Ambassador
    {
        $assignment = $this->ambassadorAssignments()->whereNull('unassigned_at')->with('ambassador')->first();

        return $assignment?->ambassador;
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

    /**
     * `$this->features` (the magic relation PROPERTY) — not `$this->features()->pluck(...)`
     * (the relation query builder). Real bug found in a scale audit (see
     * docs/SCALING_GUIDE.md): the query-builder form runs a fresh
     * `shop_features` join every single call and never populates
     * `$this->relations['features']`, so the docblock's "cached
     * per-request" claim above was never actually true — every one of
     * this request's `hasFeature()` calls (6+ inside one checkout, 9+ on
     * the Reports page) re-queried from scratch. Property access lazy-
     * loads once and Eloquent caches it on the instance from then on.
     */
    public function featureKeys(): Collection
    {
        return $this->features->pluck('key');
    }

    /**
     * Security-audit fix: every place that hands this model straight to
     * Inertia::render() (HomeController, MoreController, BarcodeLabelController,
     * OnboardingController, HandleInertiaRequests' shared prop, the mobile
     * API's Api\HomeController/AuthController) was shipping cash_balance/
     * bank_balance/capital/monthly_fee to ANY logged-in shop user, even a
     * cashier the owner never granted the `accounts` permission to —
     * readable straight out of the page's own JSON via devtools/view-source,
     * even though the Accounts page itself was correctly blocked server-
     * side. AccountsController builds its own explicit response (its
     * `cash`/`bank`/`capital` keys), so this never touches that page.
     */
    public function toArrayForUser(?User $user): array
    {
        $data = $this->toArray();

        if (! $user || ! $user->hasPermission('accounts')) {
            unset($data['cash_balance'], $data['bank_balance'], $data['capital'], $data['monthly_fee']);
        }

        // resolved (defaults-merged) versions, not the raw possibly-null/
        // partial JSON column -- every consumer of the shared `shop` prop
        // (memo print, label print, the settings page itself) reads these
        // two keys and never needs to know about MEMO_SETTINGS_DEFAULTS/
        // LABEL_SETTINGS_DEFAULTS itself.
        $data['memo_settings'] = $this->memoSettings();
        $data['label_settings'] = $this->labelSettings();
        $data['mobile_nav_items'] = $this->mobileNavItems();

        return $data;
    }

    /**
     * Demo/trial/internal shops exist on the platform (showcasing it,
     * prospective signups, Khaled's own ops shops) but aren't real paying
     * customers — Dashboard/Analytics revenue and shop-count figures use
     * this scope so they only ever reflect the paid book of business.
     */
    public function scopePaidAccounts($query)
    {
        return $query->where('account_type', 'paid');
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

    /** Null until the owner first opens the catalog settings page (CatalogSettingController::index lazily assigns catalog_slug) — see docs/FIX_PLAN.md's "ডিজিটাল ক্যাটালগ" design note. */
    public function catalogUrl(): ?string
    {
        return $this->catalog_slug ? route('catalog.show', $this->catalog_slug) : null;
    }

    /**
     * Root-cause fix (recurring "POS won't add to cart" bug): this shop's
     * actual vertical (e.g. 'restaurant', 'clothing', 'grocery'), independent
     * of which *features* happen to be granted to it. Before this existed,
     * several places (server routing in PosController/RestaurantTableController,
     * and AppLayout.vue/Stock/Index.vue/Accounts/Index.vue/More.vue on the
     * frontend) used hasFeature('restaurant_tables') as a stand-in for "is
     * this a restaurant" — which breaks the moment a shop has that feature
     * granted without actually being a restaurant (e.g. the flagship demo
     * shop, seeded with every feature that exists to showcase the platform).
     * Every "which screen/flow" decision must check THIS, not a feature flag
     * — feature flags stay purely additive-capability, never identity.
     * Appended so it's always present on the shared `shop` Inertia prop.
     */
    protected function businessTypeSlug(): Attribute
    {
        return Attribute::get(fn () => $this->businessType?->slug);
    }

    /** See businessTypeSlug() docblock — the one check every restaurant-only routing decision (server or client) should use. */
    public function isRestaurant(): bool
    {
        return $this->business_type_slug === 'restaurant';
    }

    // Invoice numbers are assigned inside PosController::checkout using an
    // explicit lockForUpdate() select — not here — so concurrent checkouts
    // for the same shop can never be handed the same invoice number.
}
