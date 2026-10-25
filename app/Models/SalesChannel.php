<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** "Online Business" module, part 1 — see docs/FIX_PLAN.md's Phase 31 design note. */
class SalesChannel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shop_id', 'name', 'platform_type', 'logo_path', 'active',
        'default_courier_id', 'order_prefix', 'platform_fee_percent', 'gateway_fee_percent',
    ];

    public const PLATFORM_TYPES = ['facebook', 'website', 'daraz', 'whatsapp', 'catalog', 'other'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'platform_fee_percent' => 'decimal:2',
            'gateway_fee_percent' => 'decimal:2',
        ];
    }

    protected $appends = ['logo_url'];

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function priceOverrides()
    {
        return $this->hasMany(ChannelProductPrice::class, 'channel_id');
    }

    public function defaultCourier()
    {
        return $this->belongsTo(Courier::class, 'default_courier_id');
    }

    /**
     * The price this channel sells a product at — its own override if one
     * exists, otherwise the exact same fallback POS checkout already uses
     * (`discount_price ?? price`). Part 2's online-order pricing calls this
     * rather than re-deriving the fallback, per the module's own ground
     * rule that online pricing must never be a second pricing engine.
     */
    public function priceForProduct(Product $product): float
    {
        $override = $this->relationLoaded('priceOverrides')
            ? $this->priceOverrides->firstWhere('product_id', $product->id)
            : $this->priceOverrides()->where('product_id', $product->id)->first();

        return $override ? (float) $override->price : (float) ($product->discount_price ?? $product->price);
    }
}
