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
        'shop_id', 'category_id', 'unit_id', 'name', 'name_en', 'generic_name', 'company', 'shelf_location', 'requires_prescription', 'emoji', 'photo_path', 'barcode',
        'sold_by_weight', 'weight_unit',
        'cost', 'price', 'wholesale_price', 'discount_price', 'stock', 'expiry_date', 'batch_no', 'size', 'color', 'imei',
        'reorder_point',
    ];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            // 3 decimal places — enough to record a single gram (0.001 kg)
            // or millilitre (0.001 litre) precisely; every other quantity
            // in the system (checkout, stock-in, damage, return, stock
            // count) casts to the same precision so nothing rounds
            // differently at different points in the same number's life
            'stock' => 'decimal:3',
            'expiry_date' => 'date',
            'requires_prescription' => 'boolean',
            'sold_by_weight' => 'boolean',
        ];
    }

    /** Real photo, when the shop has uploaded one — the emoji stays as the always-available fallback icon. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
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
        // rather than relying on PHP's loose == coercion
        return (float) $this->stock > 0 && (float) $this->stock <= 6;
    }

    public function isOut(): bool
    {
        return (float) $this->stock <= 0;
    }

    public function displayName(string $lang = 'bn'): string
    {
        return $lang === 'bn' ? $this->name : ($this->name_en ?: $this->name);
    }
}
