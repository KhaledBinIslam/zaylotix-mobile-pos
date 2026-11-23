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
        'shop_id', 'master_product_id', 'supplier_id', 'brand_id', 'category_id', 'unit_id', 'name', 'name_en', 'generic_name', 'company', 'shelf_location', 'requires_prescription', 'emoji', 'photo_path', 'barcode',
        'sold_by_weight', 'weight_unit', 'track_serial',
        'cost', 'price', 'wholesale_price', 'wholesale_moq', 'discount_price', 'stock', 'stock_mode', 'expiry_date', 'batch_no', 'size', 'color', 'imei',
        'rental_mode', 'rent_price',
        'option1_label', 'option2_label', // SEAMAN Phase 3 — what this product CALLS its colour/size axes (null = রং/সাইজ, every pre-existing product)
        'reorder_point', 'show_in_catalog', 'search_keywords',
        'internal_note', // SEAMAN Phase 6 — importer's VERIFY remarks; never shown on any customer-facing copy
    ];

    /** 'toggle' mode's stock column doesn't hold a real count — 1 means available, 0 means marked sold out today (see the add_stock_mode_to_products_table migration and TableOrderController). */
    public const STOCK_MODE_TRACKED = 'tracked';

    public const STOCK_MODE_UNTRACKED = 'untracked';

    public const STOCK_MODE_TOGGLE = 'toggle';

    /**
     * Set only around CatalogSync::upsertBranchProduct()'s own restore of a
     * re-entered article's tombstoned branch copy — CatalogSync runs its
     * OWN variant revival + stock-reset-to-new-article logic right after
     * (syncVariantsOfProduct()), keyed on the variant still being trashed
     * at that point. Everywhere else, restored() keeps restoring a
     * product's own variants automatically (2026-11-23 fix).
     */
    public static bool $suppressAutoVariantRestore = false;

    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'wholesale_moq' => 'integer',
            'discount_price' => 'decimal:2',
            'rent_price' => 'decimal:2',
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

        /*
         * Khaled's rule (2026-11-23, final): in a shared_stock_pool group
         * a product created in ANY shop — POS quick item, plain create,
         * import, opening stock, anything future — exists in EVERY shop
         * of the group immediately, linked and with one stock figure.
         * A branch-only product in a pooled shop is never allowed.
         *
         * Done as a model event rather than per-controller calls so no
         * creation path can ever be missed. CatalogSync::$syncing marks
         * the rows CatalogSync itself creates (lifted masters, pushed
         * branch copies) — those are the sync's own work, not a new
         * article entering the business.
         */
        static::created(function (Product $product) {
            if ($product->master_product_id !== null) {
                return;
            }

            $shop = \App\Support\CatalogSync::creationHookShop($product->shop_id);
            if (! $shop) {
                return;
            }

            try {
                \App\Support\CatalogSync::syncOneProduct($shop, $product);
            } catch (\Throwable $e) {
                // the owner's own save must never fail because the pool
                // copy couldn't be made — log it and let the manual
                // "ক্যাটালগ সিঙ্ক" button catch the row up later
                \Illuminate\Support\Facades\Log::error('pool_auto_adopt: product create sync failed', [
                    'product_id' => $product->id, 'shop_id' => $product->shop_id, 'error' => $e->getMessage(),
                ]);
            }
        });

        // Soft-deleting a product used to leave its variants behind as live
        // rows, which bites the moment an owner removes a mis-entered
        // article: a sticker printed for one of those variants still
        // resolved on a barcode scan, to a product that no longer exists
        // (PosController's variant branch then worked with a null product).
        // Hiding them is only half of it, so the cascade also hands their
        // barcodes back — see releaseBarcodes() for why that is safe.
        //
        // Soft, like the parent, and mirrored by restored() below, so
        // undeleting a product brings its sizes/colours back with it.
        // Skipped on a force delete — the admin hard-delete path, where the
        // foreign key's own cascade removes the rows outright.
        static::deleted(function (Product $product) {
            if ($product->isForceDeleting()) {
                return;
            }

            $product->releaseBarcodes();
            $product->deleteBranchCopies();
        });

        static::restored(function (Product $product) {
            // the variants come back; their barcodes don't, since the
            // serial may well have been reissued in the meantime — run
            // articles:regenerate-barcodes to mint fresh ones.
            //
            // withoutGlobalScopes(), not the $product->variants() relation:
            // ProductVariant carries the tenant global scope, so the plain
            // relation silently restores NOTHING when this fires for a
            // DIFFERENT shop than the ambient Tenancy::id() -- exactly the
            // case when this event fires for the master/sibling products a
            // branch restore just cascaded to (2026-11-23 fix; found by the
            // new cross-shop restore test, not previously exercised).
            if (! static::$suppressAutoVariantRestore) {
                ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->onlyTrashed()->get()->each->restore();
            }

            if ($product->master_product_id === null) {
                $thisShop = Tenancy::id() === $product->shop_id ? Tenancy::shop() : $product->shop;
                if ($thisShop && $thisShop->hasFeature('shared_stock_pool')) {
                    foreach (static::withoutGlobalScopes()->onlyTrashed()->whereIn('id', static::validBranchCopyIds($product->id, $thisShop->id))->get() as $copy) {
                        $copy->restore();
                    }
                } else {
                    // shared_catalog-only: original unconditional behavior
                    foreach (static::withoutGlobalScopes()->onlyTrashed()->where('master_product_id', $product->id)->get() as $copy) {
                        $copy->restore();
                    }
                }
            }

            // mirror of deleteBranchCopies()'s own symmetric fix — restoring
            // a pooled BRANCH copy must bring the master (and every other
            // sibling the delete took down with it) back too, or they stay
            // stuck deleted on every other shop forever. Same validity check
            // as deleteBranchCopies() — never inferred from master_product_id
            // alone (see validBranchCopyIds() docblock).
            if ($product->master_product_id !== null) {
                $master = static::withoutGlobalScopes()->find($product->master_product_id);
                $thisShop = $product->shop;
                $thisIsValidSibling = $thisShop && $thisShop->parent_shop_id === $master?->shop_id && $thisShop->account_type !== 'demo';

                if ($master && $master->shop?->hasFeature('shared_stock_pool') && $thisIsValidSibling) {
                    $validIds = static::validBranchCopyIds($product->master_product_id, $master->shop_id)
                        ->reject(fn ($id) => $id === $product->id);

                    $siblings = static::withoutGlobalScopes()
                        ->onlyTrashed()
                        ->where(fn ($q) => $q->whereKey($product->master_product_id)->orWhereIn('id', $validIds))
                        ->get();

                    if ($siblings->isNotEmpty()) {
                        static::logPoolCascade('product.pool_restore_cascade', $product, $siblings, 'ফিরিয়ে আনা হয়েছে');
                    }

                    $siblings->each(fn (self $sibling) => $sibling->restore());
                }
            }
        });
    }

    /**
     * Give back every barcode this article and its variants hold.
     *
     * A soft-deleted row still occupies the unique (shop_id, barcode)
     * index, so hiding an article was not enough to let its serial be
     * entered again — which is what Khaled asked for. Nothing is lost:
     * barcodes are derived data (articles:regenerate-barcodes rebuilds
     * them from the article and its colours), sale history snapshots the
     * product name and price rather than a barcode, and there is no
     * restore-a-deleted-product screen for them to come back to.
     *
     * Every query goes through withoutGlobalScopes() and filters on
     * product_id alone. That already pins a single shop's rows, and it
     * keeps this correct from a console command or a migration, where
     * TenantScope resolves to shop_id = 0 and would match nothing.
     * Trashed variants are included on purpose: the cascade shipped
     * before this release did (d7e396ea), so it hid variants while
     * leaving their barcodes reserved — those are exactly the rows a
     * reused serial collides with.
     */
    public function releaseBarcodes(): void
    {
        // soft-delete the ones still live, keeping an earlier cascade's own
        // timestamp wherever it already set one
        ProductVariant::withoutGlobalScopes()->where('product_id', $this->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $this->deleted_at ?? now()]);

        ProductVariant::withoutGlobalScopes()->where('product_id', $this->id)
            ->update(['barcode' => null]);

        ProductColorBarcode::withoutGlobalScopes()->where('product_id', $this->id)->delete();
        ProductColorBarcodeAlias::withoutGlobalScopes()->where('product_id', $this->id)->delete();

        static::withoutGlobalScopes()->whereKey($this->id)->update(['barcode' => null]);
    }

    /**
     * Take the branch's copies of this article down with it.
     *
     * A shop with `shared_catalog` gets a copy of every article in each of
     * its branches, linked back by `master_product_id` (CatalogSync). The
     * delete only ever reached the main shop's own row, so the branch kept
     * the article on its shelves and on its POS screen after the owner had
     * removed it — and, because a serial counts as used anywhere in the
     * business, the number never came free either. That is what Khaled was
     * seeing: "delete kore dileo 7 8 ekhono aseni", with the doctor
     * reporting all ten serials live and none freed.
     *
     * Each copy is deleted through the model, not a mass update, so it
     * runs this same hook for itself: its variants are hidden and its
     * barcodes handed back exactly like the main shop's. A copy has no
     * copies of its own, so it stops there. Mirrored by restored().
     *
     * withoutGlobalScopes() because the copies belong to the BRANCH's
     * shop_id — a tenant-scoped query from the main shop sees none of them.
     *
     * Real incident, Baby Park, 2026-11-22: a branch deleted ITS OWN copy
     * of a pooled article (shared_stock_pool, not just shared_catalog) —
     * this cascade used to only run master -> branches, so the master
     * shop's row (and the ~15.5 lakh of stock value genuinely shared with
     * it) was left completely untouched, and the two branches' Accounts
     * pages instantly disagreed about the same physical stock. For a
     * shop with shared_stock_pool on, deletion must be symmetric in
     * EITHER direction — it's one shared product, not a one-way catalog
     * push, so removing it anywhere removes it everywhere. Plain
     * shared_catalog (no pool) keeps the original one-way master-only
     * cascade unchanged — every explicit whereNull('deleted_at') below
     * guards against the resulting recursion (deleting a sibling re-fires
     * this same hook on it) ever looping past rows already handled.
     */
    public function deleteBranchCopies(): void
    {
        if ($this->master_product_id !== null) {
            $master = static::withoutGlobalScopes()->find($this->master_product_id);
            $thisShop = $this->shop;
            $poolOn = $master && ! $master->trashed() && $master->shop?->hasFeature('shared_stock_pool');
            // $this itself must be a genuine branch of $master, never inferred
            // from master_product_id alone (see validBranchCopyIds() docblock)
            $thisIsValidSibling = $thisShop && $thisShop->parent_shop_id === $master?->shop_id && $thisShop->account_type !== 'demo';

            if ($poolOn && $thisIsValidSibling) {
                $validIds = static::validBranchCopyIds($this->master_product_id, $master->shop_id)
                    ->reject(fn ($id) => $id === $this->id);

                $siblings = static::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->whereKey($this->master_product_id)->orWhereIn('id', $validIds))
                    ->get();

                if ($siblings->isNotEmpty()) {
                    static::logPoolCascade('product.pool_delete_cascade', $this, $siblings, 'মুছে ফেলা হয়েছে');
                }

                $siblings->each(fn (self $sibling) => $sibling->delete());
            }

            return; // a plain (non-pooled, or non-sibling) branch copy has nothing else hanging off it
        }

        $thisShop = Tenancy::id() === $this->shop_id ? Tenancy::shop() : $this->shop;
        if (! $thisShop || ! $thisShop->hasFeature('shared_stock_pool')) {
            // shared_catalog-only (no pool): keep the original, unconditional
            // one-way cascade -- every existing branch copy comes down,
            // demo-cloning risk doesn't apply here since it's catalog push,
            // not a shared stock number
            foreach (static::withoutGlobalScopes()->whereNull('deleted_at')->where('master_product_id', $this->id)->get() as $copy) {
                $copy->delete();
            }

            return;
        }

        foreach (static::withoutGlobalScopes()->whereIn('id', static::validBranchCopyIds($this->id, $thisShop->id))->whereNull('deleted_at')->get() as $copy) {
            $copy->delete();
        }
    }

    /**
     * One ActivityLog row per shop touched by a pool-wide cascade (delete
     * or restore), explicit shop_id per row since Activity::log()'s own
     * Tenancy::id() shortcut would attribute every row to whichever shop
     * happens to be the active tenant, not the shop the row actually
     * belongs to — wrong for every sibling but the one that triggered this.
     */
    private static function logPoolCascade(string $action, self $trigger, Collection $siblings, string $verbBn): void
    {
        $userId = \Illuminate\Support\Facades\Auth::guard('web')->id() ?? \Illuminate\Support\Facades\Auth::guard('sanctum')->id();
        $all = $siblings->push($trigger)->unique('id')->values();
        $shopNames = $all->mapWithKeys(fn (self $p) => [$p->shop_id => $p->shop?->name ?? "Shop #{$p->shop_id}"]);
        $branchList = $shopNames->values()->implode('، ');
        $affectedIds = $all->pluck('id')->values()->all();

        foreach ($all as $row) {
            \App\Models\ActivityLog::create([
                'shop_id' => $row->shop_id,
                'user_id' => $userId,
                'action' => $action,
                'subject_type' => self::class,
                'subject_id' => $row->id,
                'description' => "পণ্য '{$trigger->name}' শেয়ার্ড স্টক পুল-এ থাকা সব শাখায় {$verbBn} — {$branchList}।",
                'meta' => ['triggered_by_product_id' => $trigger->id, 'triggered_by_shop_id' => $trigger->shop_id, 'affected_product_ids' => $affectedIds],
                'created_at' => now(),
            ]);
        }
    }

    /** Every field a cashier might type to find this product, normalized once and stored — see SearchNormalizer::normalize(). */
    public function buildSearchText(): string
    {
        $parts = [$this->name, $this->name_en, $this->generic_name, $this->barcode, $this->search_keywords];

        // Khaled live: "sujon search korchi kichu asena" — a product's
        // supplier name was never in here at all (brand/category weren't
        // either — see ProductSearch's own docblock on what it covers),
        // so typing a supplier never found anything. For a source_lines
        // shop this pulls every distinct supplier across the product's
        // OWN line and every variant's (one query — product_id is set on
        // both, per the create_product_sources migration); a product with
        // no lines yet is unaffected. search_text only goes stale here if
        // nothing ever re-saves the product after a line changes — see
        // ProductSource::upsertLine()'s touch() for why that can't happen.
        if ($this->id && Tenancy::shop()?->hasFeature('source_lines')) {
            $supplierNames = ProductSource::where('product_id', $this->id)
                ->with('supplier:id,name')->get()
                ->pluck('supplier.name')->filter()->unique()->all();
            $parts = array_merge($parts, $supplierNames);
        }

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
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /** Part 1 (source_lines) — this VARIANTLESS product's own supplier/brand lots (a product WITH variants has its lines on each variant instead). */
    public function sources()
    {
        return $this->hasMany(ProductSource::class)->whereNull('product_variant_id')->orderBy('id');
    }

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

    /** docs/ARTICLE_SERIES_PLAN.md §9 — one row per color this article comes in, the scannable "tier 2" sticker barcode. */
    public function colorBarcodes()
    {
        return $this->hasMany(ProductColorBarcode::class);
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
                ->merge(static::validBranchCopyIds($masterId, $master->shop_id))
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
        $branchCopyIds = static::validBranchCopyIds($this->id, $shop->id);
        if ($branchCopyIds->isEmpty()) {
            return collect([$this->id]);
        }

        return collect([$this->id])->merge($branchCopyIds)->unique()->values();
    }

    /**
     * Real incident, 2026-11-22: two demo shops (cloned FROM Baby Park and
     * SEAMAN for sales demos) kept their products' master_product_id
     * pointing at those real shops' real products — 211 rows platform-wide
     * — so a plain `where('master_product_id', $masterId)` treated an
     * unrelated demo account as a genuine pool sibling. Confirmed live
     * contamination both directions: real sales inside the demo shop
     * decremented the real shop's pooled stock, and editing the real
     * shop's stock pushed a value into the demo shop's copy.
     *
     * A product is only a genuine pool sibling if its OWN shop is an
     * actual branch of the master's shop (`parent_shop_id` matches) AND
     * isn't a demo/internal account — never inferred from
     * master_product_id alone. Every reader of master_product_id for
     * pooling purposes (this method, deleteBranchCopies(), restored(),
     * ProductsReconcileDeletedPool) goes through this one query so the
     * definition can't drift between them again.
     */
    public static function validBranchCopyIds(int $masterId, int $masterShopId): Collection
    {
        return static::withoutGlobalScopes()
            ->join('shops', 'shops.id', '=', 'products.shop_id')
            ->where('products.master_product_id', $masterId)
            ->where('shops.parent_shop_id', $masterShopId)
            ->where('shops.account_type', '!=', 'demo')
            ->pluck('products.id');
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
    /**
     * Real bug Khaled hit live: a variant product with one size/color down
     * to its own reorder_point (e.g. 1 pair left, threshold 3) never showed
     * up here, because this scope only ever compared the product's own
     * TOTAL stock against a hardcoded 6 — it ignored reorder_point
     * entirely (despite SendLowStockAlerts already doing this correctly,
     * per-product AND per-variant) and had no concept of "one specific
     * size/color is low even though the combined total looks fine".
     *
     * Now: a product counts as low when EITHER its own total is at/under
     * its own reorder_point (falling back to the generic default — 6
     * pieces, or 1 for a weighed product's much tighter line — only when
     * reorder_point isn't set, same convention Pos/Index.vue's JS already
     * uses), OR any one of its variants is at/under THAT variant's own
     * reorder_point. Still excludes 0 (that's "out of stock", a separate tile).
     */
    public function scopeLowStock($query)
    {
        return $query->where('stock_mode', self::STOCK_MODE_TRACKED)->where(function ($w) {
            $w->where(function ($own) {
                $own->where('stock', '>', 0)->where(fn ($t) => $t
                    ->where(fn ($q) => $q->where('sold_by_weight', false)->whereRaw('stock <= COALESCE(reorder_point, 6)'))
                    ->orWhere(fn ($q) => $q->where('sold_by_weight', true)->whereRaw('stock <= COALESCE(reorder_point, 1)')));
            })->orWhereHas('variants', fn ($v) => $v->whereNotNull('reorder_point')->where('stock', '>', 0)->whereColumn('stock', '<=', 'reorder_point'));
        });
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_mode', self::STOCK_MODE_TRACKED)->where('stock', '<=', 0);
    }

    public function displayName(string $lang = 'bn'): string
    {
        return $lang === 'bn' ? $this->name : ($this->name_en ?: $this->name);
    }

    public const RENTAL_MODE_SELL_ONLY = 'sell_only';

    public const RENTAL_MODE_RENT_ONLY = 'rent_only';

    public const RENTAL_MODE_BOTH = 'both';

    public function canBeRented(): bool
    {
        return in_array($this->rental_mode, [self::RENTAL_MODE_RENT_ONLY, self::RENTAL_MODE_BOTH], true);
    }

    public function canBeSold(): bool
    {
        return $this->rental_mode !== self::RENTAL_MODE_RENT_ONLY;
    }

    /**
     * Currently out on rent (qty across every still-open RentalItem for this
     * product). Renting never decrements `stock` itself — the item is still
     * shop property, just physically with a customer — so "how many are
     * actually out right now" has to be computed from rental_items, not
     * read off a stored column.
     */
    public function rentedQty(): float
    {
        // whereNull(product_variant_id): a variant's own rentals are
        // tracked on ProductVariant::rentedQty() instead, so a product
        // with variants never double-counts them here.
        return (float) RentalItem::where('product_id', $this->id)->whereNull('product_variant_id')->where('status', 'out')->sum('qty');
    }

    public function availableForRent(): float
    {
        return max(0, (float) $this->stock - $this->rentedQty());
    }

    public function rentalDamagedQty(): float
    {
        return (float) RentalReturn::whereHas('item', fn ($q) => $q->where('product_id', $this->id)->whereNull('product_variant_id'))->where('condition', 'damaged')->sum('qty');
    }

    public function rentalLostQty(): float
    {
        return (float) RentalReturn::whereHas('item', fn ($q) => $q->where('product_id', $this->id)->whereNull('product_variant_id'))->where('condition', 'lost')->sum('qty');
    }
}
