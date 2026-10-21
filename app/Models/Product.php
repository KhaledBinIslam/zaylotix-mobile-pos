<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'shop_id', 'master_product_id', 'category_id', 'unit_id', 'name', 'name_en', 'generic_name', 'company', 'shelf_location', 'requires_prescription', 'emoji', 'photo_path', 'barcode',
        'sold_by_weight', 'weight_unit', 'track_serial',
        'cost', 'price', 'wholesale_price', 'wholesale_moq', 'discount_price', 'stock', 'stock_mode', 'expiry_date', 'batch_no', 'size', 'color', 'imei',
        'reorder_point', 'show_in_catalog',
    ];

    /** 'toggle' mode's stock column doesn't hold a real count — 1 means available, 0 means marked sold out today (see the add_stock_mode_to_products_table migration and TableOrderController). */
    public const STOCK_MODE_TRACKED = 'tracked';

    public const STOCK_MODE_UNTRACKED = 'untracked';

    public const STOCK_MODE_TOGGLE = 'toggle';

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'wholesale_moq' => 'integer',
            'discount_price' => 'decimal:2',
            // 3 decimal places — enough to record a single gram (0.001 kg)
            // or millilitre (0.001 litre) precisely; every other quantity
            // in the system (checkout, stock-in, damage, return, stock
            // count) casts to the same precision so nothing rounds
            // differently at different points in the same number's life
            'stock' => 'decimal:3',
            'expiry_date' => 'date:Y-m-d',
            'requires_prescription' => 'boolean',
            'sold_by_weight' => 'boolean',
            'track_serial' => 'boolean',
            'show_in_catalog' => 'boolean',
        ];
    }

    /** Real photo, when the shop has uploaded one — the emoji stays as the always-available fallback icon. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null);
    }

    /** False for 'untracked' (always sellable) and 'toggle' (a plain switch, not a count) — only 'tracked' products have a stock number that means anything. */
    public function isStockTracked(): bool
    {
        return $this->stock_mode === self::STOCK_MODE_TRACKED;
    }

    /** Only meaningful for 'toggle' mode — see the migration's comment on why stock doubles as this flag there. */
    public function isMarkedAvailable(): bool
    {
        return (float) $this->stock > 0;
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    /** The main shop's own row this branch product is linked to — see docs/FIX_PLAN.md's "শেয়ার্ড ক্যাটালগ" design note (CatalogSync). Null for a main shop's own products and for any shop not using shared_catalog. */
    public function masterProduct()
    {
        return $this->belongsTo(Product::class, 'master_product_id');
    }

    /** Every branch row currently linked to this one (only ever non-empty on a main shop's own product). */
    public function branchCopies()
    {
        return $this->hasMany(Product::class, 'master_product_id');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** The recipe (ingredient + qty_per_unit) this product consumes when sold/prepared — empty for a product with no ingredient tracking set up. */
    public function recipes()
    {
        return $this->hasMany(ProductRecipe::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /** Alternate pack sizes (box/strip/...) this product can also be sold as — see product_units table. */
    public function productUnits()
    {
        return $this->hasMany(ProductUnit::class);
    }

    /** All tracked batches — only meaningful for shops with the batch_tracking feature. */
    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    /** True variants (size/color) with their own independent stock — see product_variants table. */
    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Quantity-break wholesale prices (see docs/FIX_PLAN.md's Phase 30 design note, Part A) — only meaningful under the wholesale_tiers feature; empty for a product that only uses the flat wholesale_price. */
    public function priceTiers()
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_qty');
    }

    /** The richest tier this qty clears, or null if no tier applies (falls back to the flat wholesale_price/retail price). Tiers are expected pre-sorted ascending by min_qty — callers that already eager-loaded priceTiers() via the orderBy above get that for free. */
    public function wholesaleTierPriceForQty(float $qty): ?float
    {
        $applicable = $this->priceTiers->filter(fn (ProductPriceTier $tier) => $qty >= $tier->min_qty);

        return $applicable->isEmpty() ? null : (float) $applicable->last()->price;
    }

    /** Per-unit IMEI/serial records — only meaningful for shops with the serial_tracking feature. */
    public function serials()
    {
        return $this->hasMany(ProductSerial::class);
    }

    /** The single soonest-expiring batch still in stock, if this product has any tracked batches. */
    public function nearestBatch()
    {
        return $this->hasOne(ProductBatch::class)->available()->fefoOrder();
    }

    public function isLow(): bool
    {
        // decimal:3 makes $this->stock a numeric string (precision safety
        // for weighed products) — every comparison here casts explicitly
        // rather than relying on PHP's loose == coercion. A weighed
        // product's real "running low" line is much tighter (a fraction of
        // one kg/litre) than a piece-counted product's — see scopeLowStock().
        return $this->stock_mode === self::STOCK_MODE_TRACKED
            && (float) $this->stock > 0
            && (float) $this->stock <= ($this->sold_by_weight ? 1 : 6);
    }

    public function isOut(): bool
    {
        return $this->stock_mode === self::STOCK_MODE_TRACKED && (float) $this->stock <= 0;
    }

    /** Kept in sync everywhere this concept appears (Home's low-stock
     *  tile, Stock's own copy of it, and the stock_status=low filter
     *  tapping either now opens to) — these used to be three separately
     *  hand-written copies of the same threshold that quietly drifted
     *  apart (a weighed product's real "low" line is much tighter than a
     *  piece-counted one's, and a negative-stock product from an
     *  over-return wasn't consistently caught as "out"). 'untracked'/
     *  'toggle' restaurant items are excluded — see STOCK_MODE_* docblock. */
    public function scopeLowStock($query)
    {
        return $query->where('stock_mode', self::STOCK_MODE_TRACKED)->where(fn ($w) => $w
            ->where(fn ($ww) => $ww->where('sold_by_weight', false)->whereBetween('stock', [0.01, 6]))
            ->orWhere(fn ($ww) => $ww->where('sold_by_weight', true)->whereBetween('stock', [0.01, 1])));
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_mode', self::STOCK_MODE_TRACKED)->where('stock', '<=', 0);
    }

    public function displayName(string $lang = 'bn'): string
    {
        return $lang === 'bn' ? $this->name : ($this->name_en ?: $this->name);
    }
}
