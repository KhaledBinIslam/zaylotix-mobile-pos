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
        /*
         * Same rule as Product::created() — a size/colour entered in ANY
         * shop of a shared_stock_pool group (article grid, opening stock,
         * import, anything) exists in every shop of the group at once,
         * with the same stock figure. CatalogSync's own creates are
         * excluded via $syncing; the copies this hook itself creates are
         * excluded via $creatingCopies.
         */
        static::created(function (ProductVariant $variant) {
            try {
                $variant->createBranchCopies();
            } catch (\Throwable $e) {
                // same rule as Product::created(): the user's own save
                // never fails because a pool copy couldn't be made
                \Illuminate\Support\Facades\Log::error('pool_auto_adopt: variant create mirror failed', [
                    'variant_id' => $variant->id, 'shop_id' => $variant->shop_id, 'error' => $e->getMessage(),
                ]);
            }
        });

        /*
         * The opening-stock grid (and anything like it) stamps the
         * barcode AFTER creating the row ('ZV'.$id), but the pool's
         * variant matcher is barcode-FIRST (stockPoolMemberIds()): a
         * filled barcode on one side and null on its mirrored copies
         * means the pool silently stops binding them. So a barcode set
         * later flows to the cell-matched, still-barcodeless copies too.
         */
        static::updated(function (ProductVariant $variant) {
            if ($variant->wasChanged('barcode') && filled($variant->barcode)) {
                try {
                    $variant->propagateBarcodeToPoolCopies();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('pool_auto_adopt: barcode propagation failed', [
                        'variant_id' => $variant->id, 'shop_id' => $variant->shop_id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        });

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
    /** True while createBranchCopies() is writing its own mirror rows, whose created() events must not fan out again. */
    private static bool $creatingCopies = false;

    /**
     * The create-side mirror of deleteBranchCopies(): a variant born in
     * any shop of a pooled group appears on the master and every valid
     * sibling immediately, matched the same way the delete cascade
     * matches (barcode first, else case/whitespace-insensitive
     * colour+size via cellKey()), with the SAME stock figure — one pooled
     * count, whichever shop entered it. Demo shops are never sources or
     * targets.
     */
    public function createBranchCopies(): void
    {
        if (\App\Support\CatalogSync::$syncing || static::$creatingCopies) {
            return;
        }

        $targetIds = $this->pooledTargetProductIds();
        if ($targetIds === null) {
            return;
        }

        $targets = Product::withoutGlobalScopes()->whereIn('id', $targetIds)->whereNull('deleted_at')->get();
        if ($targets->isEmpty()) {
            return;
        }

        static::$creatingCopies = true;
        try {
            foreach ($targets as $target) {
                $existing = static::withoutGlobalScopes()
                    ->where('product_id', $target->id)->whereNull('deleted_at')->get()
                    ->first(fn (ProductVariant $v) => (filled($this->barcode) && $v->barcode === $this->barcode)
                        || self::cellKey($v->color, $v->size) === self::cellKey($this->color, $this->size));
                if ($existing) {
                    continue;
                }

                $barcodeFree = filled($this->barcode) && ! static::withoutGlobalScopes()
                    ->where('shop_id', $target->shop_id)->where('barcode', $this->barcode)->exists();

                // The variant ROW only — never the target's products.stock.
                // Every creation path settles the product-level figure its
                // own way (import/opening-stock: a pooled applyStockDelta()
                // that reaches these targets by itself; article create:
                // direct sum + syncOneProduct at the end, whose pooled
                // recompute covers the targets) — a recompute here would
                // double-count on top of the pooled delta.
                static::create([
                    'shop_id' => $target->shop_id, 'product_id' => $target->id,
                    'size' => $this->size, 'color' => $this->color,
                    'barcode' => $barcodeFree ? $this->barcode : null,
                    'stock' => $this->stock, 'reorder_point' => $this->reorder_point,
                    'price' => $this->price, 'cost' => $this->cost, 'photo_path' => $this->photo_path,
                ]);
            }
        } finally {
            static::$creatingCopies = false;
        }
    }

    /** See the updated() hook — a late-stamped barcode reaches the cell-matched, barcodeless pool copies so stockPoolMemberIds()'s barcode-first matching keeps binding them. */
    public function propagateBarcodeToPoolCopies(): void
    {
        if (\App\Support\CatalogSync::$syncing || static::$creatingCopies) {
            return;
        }

        $targetProductIds = $this->pooledTargetProductIds();
        if ($targetProductIds === null) {
            return;
        }

        static::$creatingCopies = true;
        try {
            $copies = static::withoutGlobalScopes()
                ->whereIn('product_id', $targetProductIds)->whereNull('deleted_at')->whereNull('barcode')->get()
                ->filter(fn (ProductVariant $v) => self::cellKey($v->color, $v->size) === self::cellKey($this->color, $this->size));

            foreach ($copies as $copy) {
                $taken = static::withoutGlobalScopes()
                    ->where('shop_id', $copy->shop_id)->where('barcode', $this->barcode)->exists();
                if (! $taken) {
                    static::withoutGlobalScopes()->whereKey($copy->id)->update(['barcode' => $this->barcode]);
                }
            }
        } finally {
            static::$creatingCopies = false;
        }
    }

    /** The pool members' product ids this variant's create/barcode changes must reach (master + valid siblings, or the valid branch copies when this IS the master's row) — null when this variant isn't in a pooled group at all. The creationHookShop() gate answers the demo/feature/kill-switch questions without touching products at all, so a non-pooled shop's variant create never pays these queries. */
    private function pooledTargetProductIds(): ?Collection
    {
        $shop = \App\Support\CatalogSync::creationHookShop($this->shop_id);
        if (! $shop) {
            return null;
        }

        $product = Product::withoutGlobalScopes()->whereKey($this->product_id)->first();
        if (! $product) {
            return null;
        }

        if ($product->master_product_id === null) {
            if ($shop->parent_shop_id !== null) {
                return null; // an unlinked product on a BRANCH is not pooled
            }

            return Product::validBranchCopyIds($product->id, $shop->id);
        }

        $master = Product::withoutGlobalScopes()->whereKey($product->master_product_id)->whereNull('deleted_at')->first();
        if (! $master || (int) $shop->parent_shop_id !== (int) $master->shop_id) {
            return null;
        }

        return Product::validBranchCopyIds($master->id, $master->shop_id)
            ->reject(fn ($id) => $id === $product->id)
            ->push($master->id);
    }

    public function deleteBranchCopies(): void
    {
        $product = Product::withoutGlobalScopes()->whereKey($this->product_id)->first();
        if (! $product) {
            return;
        }

        if ($product->master_product_id === null) {
            $branchProductIds = Product::validBranchCopyIds($product->id, $product->shop_id);
            if ($branchProductIds->isEmpty()) {
                return;
            }

            $pooled = $product->stockPoolMemberIds()->count() > 1;

            $siblings = static::withoutGlobalScopes()->whereNull('deleted_at')
                ->whereIn('product_id', $branchProductIds)->get();

            $this->cascadeDeleteTo($siblings, $pooled);

            return;
        }

        // Real incident, 2026-11-22: a variant deleted on a BRANCH's own
        // side (L-10016's "225/CK" size) left the master's matching
        // variant live and fully stocked — this used to only run
        // master -> branches, same one-way gap Product::deleteBranchCopies()
        // had before its own 2026-11-23 fix. Symmetric here too, through
        // the same validBranchCopyIds() definition (genuine branch, not a
        // demo clone) so a variant deleted in an unrelated demo shop can
        // never reach a real sibling's row.
        $master = Product::withoutGlobalScopes()->find($product->master_product_id);
        $thisShop = Shop::withoutGlobalScopes()->find($product->shop_id);
        $thisIsValidSibling = $master && $thisShop
            && (int) $thisShop->parent_shop_id === (int) $master->shop_id
            && $thisShop->account_type !== 'demo';

        if (! $master || ! $master->shop?->hasFeature('shared_stock_pool') || ! $thisIsValidSibling) {
            return;
        }

        $validProductIds = Product::validBranchCopyIds($master->id, $master->shop_id)
            ->reject(fn ($id) => $id === $product->id)
            ->push($master->id);

        $pooled = $product->stockPoolMemberIds()->count() > 1;

        $siblings = static::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('product_id', $validProductIds)->get();

        $this->cascadeDeleteTo($siblings, $pooled);
    }

    /** Same shape as Product::logPoolCascade() (one ActivityLog row per affected shop) adapted to variant fields -- variants don't carry a shop relation as directly as Product does. */
    private static function logVariantPoolCascade(self $trigger, Collection $siblings): void
    {
        $userId = \Illuminate\Support\Facades\Auth::guard('web')->id() ?? \Illuminate\Support\Facades\Auth::guard('sanctum')->id();
        $all = $siblings->push($trigger)->unique('id')->values();
        $shopNames = $all->mapWithKeys(fn (self $v) => [$v->shop_id => Shop::withoutGlobalScopes()->find($v->shop_id)?->name ?? "Shop #{$v->shop_id}"]);
        $branchList = $shopNames->values()->implode('، ');
        $label = $trigger->label() ?: "variant #{$trigger->id}";

        foreach ($all as $row) {
            ActivityLog::create([
                'shop_id' => $row->shop_id,
                'user_id' => $userId,
                'action' => 'variant.pool_delete_cascade',
                'subject_type' => self::class,
                'subject_id' => $row->id,
                'description' => "ভ্যারিয়েন্ট '{$label}' শেয়ার্ড স্টক পুল-এ থাকা সব শাখায় মুছে ফেলা হয়েছে — {$branchList}।",
                'meta' => ['triggered_by_variant_id' => $trigger->id, 'triggered_by_shop_id' => $trigger->shop_id, 'affected_variant_ids' => $all->pluck('id')->values()->all()],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Shared by both directions of deleteBranchCopies() -- matches a
     * sibling product's variants the way CatalogSync/the pool already do
     * (barcode first; else size+color, now case/whitespace-insensitive so
     * "Brown" and "BROWN", or " Navy " and "navy", are the same cell —
     * see cellKey()). Deliberately NOT normalizing punctuation
     * ("PURPLE BLACK" vs "PURPLE-BLACK" stay distinct): a genuine
     * spelling/typo difference is a data-cleanup item for the owner to
     * merge deliberately, not something a delete cascade should guess at
     * and auto-match.
     */
    private function cascadeDeleteTo(Collection $siblings, bool $pooled): void
    {
        $matches = filled($this->barcode)
            ? $siblings->filter(fn (ProductVariant $v) => $v->barcode === $this->barcode)
            : $siblings->filter(fn (ProductVariant $v) => self::cellKey($v->color, $v->size) === self::cellKey($this->color, $this->size));

        if ($matches->isEmpty()) {
            return;
        }

        self::logVariantPoolCascade($this, $matches);

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

    /** Variant counterpart of Product::rentedQty()/availableForRent()/rentalDamagedQty()/rentalLostQty() — see those docblocks. rental_mode/rent_price live on the parent Product (one rent price per article, not per size/color), so only the qty-tracking side is duplicated here. */
    public function rentedQty(): float
    {
        return (float) RentalItem::where('product_variant_id', $this->id)->where('status', 'out')->sum('qty');
    }

    public function availableForRent(): float
    {
        return max(0, (float) $this->stock - $this->rentedQty());
    }

    public function rentalDamagedQty(): float
    {
        return (float) RentalReturn::whereHas('item', fn ($q) => $q->where('product_variant_id', $this->id))->where('condition', 'damaged')->sum('qty');
    }

    public function rentalLostQty(): float
    {
        return (float) RentalReturn::whereHas('item', fn ($q) => $q->where('product_variant_id', $this->id))->where('condition', 'lost')->sum('qty');
    }
}
