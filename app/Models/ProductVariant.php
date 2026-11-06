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

    protected $fillable = ['shop_id', 'product_id', 'brand_id', 'size', 'color', 'barcode', 'stock', 'reorder_point', 'price', 'cost', 'photo_path'];

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    /**
     * Case/whitespace-insensitive identity of a (colour, size) cell —
     * 'Navy Blue' and ' navy blue' are the SAME variant. Seaman live,
     * INV-1001: the owner typed the colour twice with different
     * capitalisation, got twin variants, sold from one and watched the
     * other's stock "not move". Every create-path duplicate check goes
     * through this pair of helpers now.
     */
    public static function cellKey(?string $color, ?string $size): string
    {
        return mb_strtolower(trim((string) $color)).'|'.mb_strtolower(trim((string) $size));
    }

    /** Does this product already have a variant occupying the same (colour, size) cell, spelling differences aside? */
    public static function cellExists(int $productId, ?string $color, ?string $size): bool
    {
        $key = self::cellKey($color, $size);

        return static::where('product_id', $productId)->get(['color', 'size'])
            ->contains(fn ($v) => self::cellKey($v->color, $v->size) === $key);
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
            if ($variant->isForceDeleting()) {
                return;
            }

            // before the barcode is released, since that is what the
            // branch's copy is matched by
            $variant->deleteBranchCopies();

            if ($variant->barcode === null) {
                return;
            }

            static::withoutGlobalScopes()->whereKey($variant->id)->update(['barcode' => null]);
            $variant->barcode = null;
        });
    }

    /**
     * Take the branch's copies of this size down with it.
     *
     * Product::deleteBranchCopies() does this for a whole article; a
     * single size never did, so removing 22/WHITE at the main shop left
     * the branch still showing and selling it. Worse, the main shop then
     * re-added that size and CatalogSync gave the branch a SECOND row for
     * it — Baby Park's branch ended up with eight sizes listed twice and
     * eleven units of stock that do not exist.
     *
     * Matched the way CatalogSync itself matches: barcode first, since it
     * is kept identical across branches on purpose, else size+colour.
     *
     * Stock is the delicate part. products.stock is the live sum of its
     * variants, so a branch copy leaving has to take its units with it —
     * EXCEPT when the two shops share a stock pool, where there is only
     * one real number and the main shop's own delete already moved it for
     * both. Decrementing again there would take the same units off twice.
     *
     * A mass update rather than ->delete(): the copy would otherwise run
     * this hook for itself, and nothing hangs off a branch copy anyway.
     */
    public function deleteBranchCopies(): void
    {
        $product = Product::withoutGlobalScopes()->whereKey($this->product_id)->first();
        if (! $product || $product->master_product_id !== null) {
            return; // already a branch copy — nothing hangs off it
        }

        $branchProductIds = Product::withoutGlobalScopes()->where('master_product_id', $product->id)->pluck('id');
        if ($branchProductIds->isEmpty()) {
            return;
        }

        $pooled = $product->stockPoolMemberIds()->count() > 1;

        $siblings = static::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('product_id', $branchProductIds)->get();

        $matches = filled($this->barcode)
            ? $siblings->filter(fn (ProductVariant $v) => $v->barcode === $this->barcode)
            : $siblings->filter(fn (ProductVariant $v) => $v->size === $this->size && $v->color === $this->color);

        foreach ($matches as $copy) {
            static::withoutGlobalScopes()->whereKey($copy->id)
                ->update(['deleted_at' => now(), 'barcode' => null]);

            if (! $pooled) {
                Product::withoutGlobalScopes()->whereKey($copy->product_id)->decrement('stock', $copy->stock);
            }
        }
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

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /** Part 1 (source_lines) — this variant's supplier/brand lots, each with its own cost/price/stock/barcode. */
    public function sources()
    {
        return $this->hasMany(ProductSource::class, 'product_variant_id')->orderBy('id');
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
