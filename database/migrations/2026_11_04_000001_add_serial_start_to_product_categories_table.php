<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a category's article numbers begin.
 *
 * Khaled runs his ranges by product kind, not by one global counter:
 * "slipers holei 90000 serial a porbe / LAdies hole 10000 / babyyy 20000
 * / Pumpi 50000 / ladies cat 30000". Reading a number tells him what the
 * shoe is before he opens anything — that is the whole point of the
 * scheme, and a single 10001-upward stream destroys it.
 *
 * It belongs on the CATEGORY rather than on the code, because two
 * categories may legitimately share one code
 * (2026_11_03_000002_let_categories_share_a_code) and still want
 * different bands — Slippers and Ladies are both "L" in his shop, yet
 * one starts at 90000 and the other at 10000. Barcodes stay unique
 * because the serial itself differs; see ArticleSerial, which now keys
 * its counter on the band as well as the code.
 *
 * Nullable: a category that never set a band keeps today's behaviour
 * exactly (ArticleSerial::START, 10001), so nothing already live moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unsignedInteger('serial_start')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('serial_start');
        });
    }
};
