<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real crash, live (Demo Shop - Shoes, checkout): SQLSTATE[22003] "Out of
 * range value for column 'stock'". allow_oversell (permission-gated,
 * deliberate — an owner/cashier can explicitly sell past what's on hand,
 * e.g. before the physical recount catches up) calls
 * ProductVariant::applyStockDelta() with a negative delta that can land
 * below zero — exactly the same case 2026_07_31_000006 already made
 * products.stock signed for, but that migration's own docblock excluded
 * product_variants.stock on the reasoning "clothing sizes are always
 * whole units" — true, but irrelevant: the crash isn't about fractional
 * vs whole, it's `unsignedInteger` physically unable to store a negative
 * whole number at all. Widening to a plain signed integer (still whole
 * units, just allowed to go negative) is the same fix products.stock
 * already has, extended to the one column that was missed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE product_variants MODIFY stock INT NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE product_variants MODIFY stock INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
