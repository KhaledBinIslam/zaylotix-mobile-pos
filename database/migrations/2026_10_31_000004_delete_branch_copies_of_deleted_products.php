<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Takes down the branch copies left standing by an article that was
 * already deleted at its main shop.
 *
 * A shop with `shared_catalog` gets a copy of every article in each of
 * its branches, linked by `master_product_id`. Deleting the article only
 * ever removed the main shop's own row, so the branch went on showing and
 * selling it — and, because ArticleSerial counts a serial as used
 * anywhere in the business, the number never came free either. Khaled
 * reported exactly that twice ("delete kore dileo 7 8 ekhono aseni"),
 * with articles:settings-doctor printing all ten serials live and none
 * freed.
 *
 * Product::deleteBranchCopies() handles this from here on; this clears
 * what was already stranded. Deleted through the model so each copy runs
 * the same cascade a fresh delete would — variants hidden, barcodes
 * handed back — and re-runnable, since a copy already deleted is no
 * longer matched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Product::withoutGlobalScopes()
            ->whereNotNull('deleted_at')
            ->whereNull('master_product_id')
            ->whereExists(fn ($sub) => $sub->selectRaw(1)->from('products as copies')
                ->whereColumn('copies.master_product_id', 'products.id')
                ->whereNull('copies.deleted_at'))
            ->cursor()
            ->each(fn (Product $product) => $product->deleteBranchCopies());
    }

    public function down(): void
    {
        // Nothing to undo: restoring these would put an article the owner
        // deleted back on a branch's shelves, which is the bug.
    }
};
