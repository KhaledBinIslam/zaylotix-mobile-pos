<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Hands back the barcodes still held by products that were deleted before
 * Product::releaseBarcodes() existed.
 *
 * The serial-reuse feature (b0e042ee) offers a deleted article's number
 * again, but only releases barcodes from that point on. Khaled had already
 * deleted B-20007 and B-20008 by then, so their variants sit soft-deleted
 * WITH their barcodes — and product_variants has a unique (shop_id,
 * barcode) index that a soft-deleted row keeps occupying. Re-entering
 * B-20007, which is the exact thing he asked for, would have died on a
 * constraint violation instead of saving.
 *
 * Variant barcodes are a pure function of the article barcode, colour and
 * size, so an identical re-entry reproduces them byte for byte — this is
 * not a near miss, it is a guaranteed collision.
 *
 * Runs over every shop (releaseBarcodes() drops the global scopes itself,
 * since TenantScope resolves to shop_id = 0 in a migration), and is
 * re-runnable: releasing an already-released barcode is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Product::withoutGlobalScopes()
            ->whereNotNull('deleted_at')
            ->where(function ($q) {
                $q->whereNotNull('barcode')
                    ->orWhereExists(fn ($sub) => $sub->selectRaw(1)->from('product_variants')
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->whereNotNull('product_variants.barcode'));
            })
            ->cursor()
            ->each(fn (Product $product) => $product->releaseBarcodes());
    }

    public function down(): void
    {
        // Nothing to undo: a released barcode is derived data, rebuilt by
        // articles:regenerate-barcodes, and the rows it belonged to are
        // deleted. Re-reserving them would only re-break serial reuse.
    }
};
