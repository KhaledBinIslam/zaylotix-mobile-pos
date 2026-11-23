<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier return can now name the specific size/color being sent back
 * (one supplier_returns row per line — a multi-line submission writes
 * several rows in one transaction, sharing supplier/settlement/reason —
 * rather than a separate lines table, so every existing report that sums
 * amount/qty per row keeps working unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
