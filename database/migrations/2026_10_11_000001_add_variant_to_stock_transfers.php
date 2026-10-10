<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variant (size/color) product transfer needs one row per size moved —
 * `qty` alone on the existing from_product_id/to_product_id pair can't say
 * which size it was. Nullable: a plain (non-variant) product's transfer row
 * is unaffected, exactly as before. `variant_label` is a snapshot (same
 * reasoning as `product_name` on this same table) so a transfer's history
 * still reads correctly even if the variant is later renamed/deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('from_product_variant_id')->nullable()->after('from_product_id')->constrained('product_variants')->nullOnDelete();
            $table->foreignId('to_product_variant_id')->nullable()->after('to_product_id')->constrained('product_variants')->nullOnDelete();
            $table->string('variant_label')->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_product_variant_id');
            $table->dropConstrainedForeignId('to_product_variant_id');
            $table->dropColumn('variant_label');
        });
    }
};
