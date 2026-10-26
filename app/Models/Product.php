<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'shop_id', 'master_product_id', 'supplier_id', 'category_id', 'unit_id', 'name', 'name_en', 'generic_name', 'company', 'shelf_location', 'requires_prescription', 'emoji', 'photo_path', 'barcode',
        'sold_by_weight', 'weight_unit', 'track_serial',
        'cost', 'price', 'wholesale_price', 'wholesale_moq', 'discount_price', 'stock', 'stock_mode', 'expiry_date', 'batch_no', 'size', 'color', 'imei',
        'reorder_point', 'show_in_catalog', 'search_keywords',
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

    protected static function booted(): void
    {
        // docs/FIX_PLAN.md's search-normalization fix: search_text is kept
        // in lockstep with every field a cashier might actually search by,
        // so it's always accurate without a separate "did you forget to
        // reindex" step. Recomputed on every save (not just when a
        // relevant field is dirty) — cheap (one string concat), and avoids
        // ever missing a case where a non-obvious caller path changed one
        // of these fields without going through a dirty-tracked attribute
        // setter.
        static::saving(function (Product $product) {
            $product->search_text = $product->buildSearchText();
        });
    }

    /** Every field a cashier might type to find this product, normalized once and stored — see SearchNormalizer::normalize(). */
    public function buildSearchText(): string
    {
        $parts = [$this->name, $this->name_en, $this->generic_name, $this->barcode, $this->search_keywords];

        return \App\Support\SearchNormalizer::normalize(implode(' ', array_filter($parts, fn ($p) => $p !== null && $p !== '')));
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

    /** Which manufacturer/purchase source this article was bought from — docs/ARTICLE_SERIES_PLAN.md §1 (reuses Supplier, not a parallel model). */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
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

    /**
     * Every Product row that shares ONE physical stock count with this one
     * — just [$this->id] unless the opt-in `shared_stock_pool` feature is
     * granted to the chain's MAIN shop (same convention as `shared_catalog`
     * — see docs/FIX_PLAN.md's Phase 38 design note) AND this row actually
     * participates in a master/branch catalog link (CatalogSync). Crosses
     * the normal per-shop tenant boundary on purpose — the one other place
     * allowed to do that for products is CatalogSync itself.
     */
    public function stockPoolMemberIds(): Collection
    {
        $masterId = $this->master_product_id;

        if ($masterId) {
            $master = static::withoutGlobalScopes()->find($masterId);
            if (! $master || ! $master->shop?->hasFeature('shared_stock_pool')) {
                return collect([$this->id]);
            }

            return collect([$masterId])
                ->merge(static::withoutGlobalScopes()->where('master_product_id', $masterId)->pluck('id'))
                ->unique()
                ->values();
        }

        // Not a branch copy. Resolved with ZERO extra queries for the
        // overwhelming majority of calls (every shop that never grants
        // shared_stock_pool, and every branch's own locally-created
        // product, which is never a pool member regardless — see
        // docs/FIX_PLAN.md's Phase 38 note): a branch always has
        // parent_shop_id set, so it's ruled out before ever touching the
        // DB, and hasFeature() is already request-cached (see
        // docs/SCALING_GUIDE.md item #1) — this runs on EVERY stock
        // mutation in the app, so a cheap, cached check first matters at
        // scale. Uses Tenancy::shop() instead of the $this->shop relation
        // when they're the same row (true for the vast majority of calls,
        // since a mutation almost always acts on the current tenant's own
        // product) to reuse that same per-request cache rather than
        // triggering a fresh lazy-load query.
        $shop = Tenancy::id() === $this->shop_id ? Tenancy::shop() : $this->shop;
        if (! $shop || $shop->parent_shop_id || ! $shop->hasFeature('shared_stock_pool')) {
            return collect([$this->id]);
        }

        // Only reached for a main shop that actually has the feature on —
        // genuinely rare in practice, one query to learn whether it has
        // any branch copies at all (and reuse the result if so, rather
        // than querying it again).
        $branchCopyIds = static::withoutGlobalScopes()->where('master_product_id', $this->id)->pluck('id');
        if ($branchCopyIds->isEmpty()) {
            return collect([$this->id]);
        }

        return collect([$this->id])->merge($branchCopyIds)->unique()->values();
    }

    /**
     * The one safe way to change this product's stock by a (positive or
     * negative) amount — every stock-affecting operation in the app (sale,
     * return, damage, stock-in, purchase, stock count, branch transfer)
     * goes through this instead of a raw increment()/decrement()/update().
     *
     * Pool OFF (the default, and every pre-existing shop's exact behavior
     * today): identical to a plain `increment('stock', $delta)` on this one
     * row — same SQL, same performance, zero behavior change.
     *
     * Pool ON: every member row (the main shop's product + every linked
     * branch copy) is locked together, in a fixed ascending-id order, so
     * two concurrent sales in two different branches of the same chain can
     * never deadlock each other over this pool — then all member rows are
     * written to the same new value atomically, so the shared number can
     * never drift and a concurrent-write lost update is impossible. A
     * transfer between two branches that are BOTH in the same pool nets to
     * zero (decrement then increment the same shared number) with no
     * special-casing needed — moving shared stock "between" two branches
     * that already share it is correctly a no-op.
     *
     * Deliberately does NOT open its own DB::transaction() — every real
     * caller (checkout, return, stock-in, stock count, transfer, ...)
     * already runs inside one, same established convention as every other
     * lockForUpdate() in this codebase; locking here without that outer
     * transaction would acquire and immediately release the lock, which
     * is pointless. A genuine deadlock (two different shops' checkouts,
     * each serialized today only by its own shop-row lock — see
     * PosController::performCheckout — racing to lock the same pool at
     * the same instant) surfaces as a normal DB exception and fails that
     * one request cleanly, exactly like any other unexpected DB error
     * already does today; nothing partially commits.
     */
    public function applyStockDelta(float $delta): float
    {
        $memberIds = $this->stockPoolMemberIds();

        if ($memberIds->count() <= 1) {
            static::withoutGlobalScopes()->whereKey($this->id)->increment('stock', $delta);
            $this->stock = round((float) $this->stock + $delta, 3);

            return (float) $this->stock;
        }

        $locked = static::withoutGlobalScopes()->whereIn('id', $memberIds)->orderBy('id')->lockForUpdate()->get();
        $newStock = round((float) $locked->first()->stock + $delta, 3);
        static::withoutGlobalScopes()->whereIn('id', $memberIds)->update(['stock' => $newStock]);
        $this->stock = $newStock;

        return $newStock;
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
