<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hands back the barcodes still held by sizes that were removed before
 * ProductVariant's own `deleted` hook existed.
 *
 * product_variants has a unique (shop_id, barcode) index that a
 * soft-deleted row keeps occupying, and a variant barcode is a pure
 * function of the article, the colour and the size — so re-adding a
 * removed size reproduces the same string and collides with its own
 * tombstone. articles:regenerate-barcodes reported exactly this on Baby
 * Park: eight sizes with no barcode, none of which could be given one
 * ("8টা পারা যায়নি — ওই বারকোড আগেই কারো দখলে").
 *
 * Deliberately a plain UPDATE rather than the model: these rows are
 * already deleted, nothing hangs off them, and TenantScope resolves to
 * shop_id = 0 in a migration. Re-runnable — a released barcode is
 * already null.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_variants')
            ->whereNotNull('deleted_at')
            ->whereNotNull('barcode')
            ->update(['barcode' => null]);
    }

    public function down(): void
    {
        // Nothing to undo: a released barcode is derived data, rebuilt by
        // articles:regenerate-barcodes, and the rows it belonged to are
        // deleted. Re-reserving them would only re-break re-adding a size.
    }
};
