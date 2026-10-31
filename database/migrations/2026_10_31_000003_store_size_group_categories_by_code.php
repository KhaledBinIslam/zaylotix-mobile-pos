<?php

use App\Models\ProductCategory;
use App\Models\Shop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores "which categories price by size group" as category CODES, and
 * lifts every article setting a BRANCH owns up to the business's main shop.
 *
 * Two problems with the ids this replaces, both live:
 *
 * 1. An id only means something in the shop that stored it. Every branch
 *    has its own product_categories rows (CatalogSync matches them by
 *    name and deliberately keeps the code identical, "or the two shops'
 *    barcodes would silently diverge"), so the main shop's id for BABY is
 *    not the branch's. Translating ids between shops only works while the
 *    main shop happens to have that category too — a branch that made its
 *    own would silently fall back to name matching, which is what hid the
 *    feature from Baby Park in the first place ("BABY" matches no name).
 *    A code needs no translation and cannot go stale.
 *
 * 2. These settings are read through Shop::articleSettingsShop() as of
 *    f771ae05, which always resolves a branch to its parent, the same way
 *    labelHeader() already did. Anything a branch stored on ITSELF is
 *    therefore no longer read — and Khaled's size groups were saved
 *    exactly that way, before the rule existed. They are moved up here,
 *    so nobody has to re-save every settings screen by hand.
 *
 * Nothing is overwritten: a business whose main shop was configured keeps
 * what it has, and only a column the parent has nothing in gets filled.
 * Screens that save two columns together move together, because half a
 * colour or size-group setup is worse than none. The old
 * size_group_category_ids column is left in place, unread — it holds the
 * only record of what was picked, and dropping a column mid-deploy is a
 * risk worth nothing here.
 */
return new class extends Migration
{
    /** Columns a branch may own that are safe to lift one at a time. */
    private const SOLO = ['cost_code_digit_map', 'article_barcode_template', 'sticker_barcode_template'];

    public function up(): void
    {
        // guarded so the data half below can be re-run on its own (the
        // test for it calls up() against an already-migrated schema)
        if (! Schema::hasColumn('shops', 'size_group_category_codes')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->json('size_group_category_codes')->nullable()->after('size_group_category_ids');
            });
        }

        // main shops first: a branch must never overwrite what its own
        // parent already had, and codes are converted in place for both
        foreach (Shop::withoutGlobalScopes()->orderBy('id')->get() as $shop) {
            $codes = $this->codesFor($shop);
            if ($codes !== []) {
                $shop->update(['size_group_category_codes' => $codes]);
            }
        }

        foreach (Shop::withoutGlobalScopes()->whereNotNull('parent_shop_id')->orderBy('id')->get() as $branch) {
            $parent = Shop::withoutGlobalScopes()->find($branch->parent_shop_id);
            if (! $parent) {
                continue;
            }

            $updates = [];

            foreach (self::SOLO as $column) {
                if ($parent->{$column} === null && $branch->{$column} !== null) {
                    $updates[$column] = $branch->{$column};
                }
            }

            // the colour-code screen's pair
            if ($parent->color_code_map === null && $branch->color_code_map !== null) {
                $updates['color_code_map'] = $branch->color_code_map;
                $updates['barcode_include_size'] = $branch->barcode_include_size;
            }

            // the size-group screen's pair
            if ($parent->kids_shoe_size_groups === null && $branch->kids_shoe_size_groups !== null) {
                $updates['kids_shoe_size_groups'] = $branch->kids_shoe_size_groups;
            }
            if ($parent->size_group_category_codes === null && $branch->size_group_category_codes !== null) {
                $updates['size_group_category_codes'] = $branch->size_group_category_codes;
            }

            if ($updates !== []) {
                $parent->update($updates);
                $parent->refresh(); // a second branch must see what the first filled in
            }
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('size_group_category_codes');
        });
    }

    /** This shop's own picked ids, as the codes those categories carry. */
    private function codesFor(Shop $shop): array
    {
        $ids = array_map('intval', $shop->size_group_category_ids ?? []);
        if ($ids === []) {
            return [];
        }

        return ProductCategory::withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereIn('id', $ids)
            ->pluck('code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()->unique()->values()->all();
    }
};
