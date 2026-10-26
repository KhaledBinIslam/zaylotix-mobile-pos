<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §1 — a "manufacture/purchase source" is
 * exactly what Supplier already models (name, payable, purchases
 * relation), so this reuses it instead of a parallel table. `short_code`
 * (e.g. "SMS") feeds the article barcode's {MFR} segment. Nullable and
 * shop-scoped unique (a supplier that never buys shoes never needs one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('short_code')->nullable()->after('name');
            $table->unique(['shop_id', 'short_code']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'short_code']);
            $table->dropColumn('short_code');
        });
    }
};
