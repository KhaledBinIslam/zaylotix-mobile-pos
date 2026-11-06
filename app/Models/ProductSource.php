<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One supplier+brand "lot/line" of a product or variant — its own buying
 * price, selling price, stock and barcode. See the create_product_sources
 * migration for the full design note. Feature-gated by `source_lines`;
 * untouched products/variants never have one of these rows read or
 * written on their behalf.
 */
class ProductSource extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['shop_id', 'product_id', 'product_variant_id', 'supplier_id', 'brand_id', 'cost', 'price', 'stock', 'barcode'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'stock' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Same barcode-release-on-delete mechanism ProductVariant::booted()
        // already uses — product_sources.(shop_id, barcode) is unique, so a
        // soft-deleted line must give its barcode back or a later line can
        // never reuse the same value.
        static::deleted(function (ProductSource $source) {
            if ($source->isForceDeleting() || $source->barcode === null) {
                return;
            }
            static::withoutGlobalScopes()->whereKey($source->id)->update(['barcode' => null]);
            $source->barcode = null;
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /** No shared-catalog stock-pool concept for lines (out of scope for Part 1) — a plain increment/decrement, same shape as Product/ProductVariant::applyStockDelta() for call-site consistency. */
    public function applyStockDelta(float $delta): float
    {
        static::withoutGlobalScopes()->whereKey($this->id)->increment('stock', $delta);
        $this->stock = round((float) $this->stock + $delta, 3);

        return (float) $this->stock;
    }

    /**
     * Part 1 decision (Stock Count stays total-level in phase 1 — there's
     * no UI to say WHICH lot a physical recount under/over-counted) — the
     * mirror's delta is still applied somewhere real, oldest lot first,
     * so sum(lines.stock) never drifts from the mirror it belongs to:
     *
     * - found MORE than expected (delta > 0): the whole amount goes onto
     *   the single oldest lot (phase 1 has no way to know which lot the
     *   extra really came from; the oldest is the simplest honest guess)
     * - found LESS (delta < 0): consumed from the oldest lot first, and
     *   only spills onto the next lot once the current one is already at
     *   zero — never driven negative
     *
     * `$orderedLines` must already be ordered oldest-first (by id) and
     * locked by the caller, same as every other stock-mutating path in
     * this app.
     */
    public static function applyFifoDelta(\Illuminate\Support\Collection $orderedLines, float $delta): void
    {
        if ($orderedLines->isEmpty() || $delta == 0.0) {
            return;
        }

        if ($delta > 0) {
            $orderedLines->first()->applyStockDelta($delta);

            return;
        }

        $toRemove = abs($delta);
        foreach ($orderedLines as $line) {
            if ($toRemove <= 0) {
                break;
            }
            $take = min($toRemove, (float) $line->stock);
            if ($take > 0) {
                $line->applyStockDelta(-$take);
                $toRemove -= $take;
            }
        }
        // if every lot was already at 0 and the mirror still claims a
        // decrease (a pre-existing desync from before Part 1 shipped),
        // there's nothing left to take FROM — the mirror itself is still
        // corrected by the caller regardless, this just can't push a
        // lot below zero to chase it
    }

    /** Human label for a picker row: "Manik · ৳1290 (4)" — brand folded in only when set. */
    public function pickerLabel(): string
    {
        $who = $this->supplier?->name ?? 'Nil';
        if ($this->brand?->name) {
            $who .= ' · '.$this->brand->name;
        }

        return $who;
    }
}
