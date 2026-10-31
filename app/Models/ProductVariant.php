<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ProductVariant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['shop_id', 'product_id', 'size', 'color', 'barcode', 'stock', 'reorder_point', 'price', 'cost', 'photo_path'];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Hand the barcode back when a size is removed.
         *
         * product_variants has a unique (shop_id, barcode) index that a
         * SOFT-deleted row keeps occupying, and a variant barcode is a
         * pure function of the article, the colour and the size — so
         * re-adding the same size reproduces the same string exactly.
         * Removing 22/PINK and adding it back could therefore never get
         * its barcode again: the deleted row still held it, silently, for
         * good. Khaled's shop had eight sizes stuck like that, which
         * articles:regenerate-barcodes reported as "আগেই কারো দখলে".
         *
         * Nothing is lost — the same reasoning as Product::
         * releaseBarcodes(), which already does this for a whole article:
         * barcodes are derived data, rebuilt by
         * articles:regenerate-barcodes, and sale history snapshots the
         * product name and price rather than a barcode.
         *
         * A force delete removes the row outright, so there is no index
         * entry left to free.
         */
        static::deleted(function (ProductVariant $variant) {
            if ($variant->isForceDeleting() || $variant->barcode === null) {
                return;
            }

            static::withoutGlobalScopes()->whereKey($variant->id)->update(['barcode' => null]);
            $variant->barcode = null;
        });
    }

    /** Same convention as Product::photoUrl() — a color's "representative" photo, when one's been uploaded for it. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function effectivePrice(): float
    {
        return (float) ($this->price ?? $this->product->price);
    }

    public function effectiveCost(): float
    {
        return (float) ($this->cost ?? $this->product->cost);
    }

    public function label(): string
    {
        return collect([$this->size, $this->color])->filter()->implode(', ');
    }

    /**
     * Variant counterpart of Product::stockPoolMemberIds() — a variant has
     * no master-link column of its own, so sibling variants are matched
     * across the parent product's pool the same way CatalogSync already
     * matches them (barcode first, since it's kept identical across
     * branches on purpose; else size+color). Just [$this->id] whenever the
     * parent product itself isn't pooled (shared_stock_pool off, or no
     * master/branch link) — zero behavior change for every existing shop.
     */
    public function stockPoolMemberIds(): Collection
    {
        $product = $this->product;
        if (! $product) {
            return collect([$this->id]);
        }

        $productPoolIds = $product->stockPoolMemberIds();
        if ($productPoolIds->count() <= 1) {
            return collect([$this->id]);
        }

        $siblings = static::withoutGlobalScopes()->whereIn('product_id', $productPoolIds)->get();

        $matches = filled($this->barcode)
            ? $siblings->filter(fn (ProductVariant $v) => $v->barcode === $this->barcode)
            : $siblings->filter(fn (ProductVariant $v) => $v->size === $this->size && $v->color === $this->color);

        return $matches->pluck('id')->unique()->values();
    }

    /** Variant counterpart of Product::applyStockDelta() — see its docblock for the full pooling/locking/deadlock rationale, identical here. */
    public function applyStockDelta(float $delta): float
    {
        $memberIds = $this->stockPoolMemberIds();

        if ($memberIds->count() <= 1) {
            static::withoutGlobalScopes()->whereKey($this->id)->increment('stock', $delta);
            $this->stock = (int) round((float) $this->stock + $delta);

            return (float) $this->stock;
        }

        $locked = static::withoutGlobalScopes()->whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();
        $newStock = (int) round((float) $locked->first()->stock + $delta);
        static::withoutGlobalScopes()->whereIn('id', $memberIds)->update(['stock' => $newStock]);
        $this->stock = $newStock;

        return $newStock;
    }
}
