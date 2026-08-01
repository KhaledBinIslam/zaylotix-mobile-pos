<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-wise damage/return — when a shop has batch_tracking on, the cashier
 * can now name *which* physical batch the damaged/returned units actually
 * came from, so that batch's own qty (and therefore future FEFO/expiry
 * alerts) stays accurate instead of only ever moving products.stock while
 * the batch breakdown silently drifts. Nullable and purely additive: a shop
 * without batch tracking (or a cashier who just doesn't specify one) keeps
 * working exactly as before — only products.stock moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->foreignId('product_batch_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->foreignId('product_batch_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_batch_id');
        });

        Schema::table('damages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_batch_id');
        });
    }
};
