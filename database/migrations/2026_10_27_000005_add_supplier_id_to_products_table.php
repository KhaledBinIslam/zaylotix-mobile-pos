<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §1 — which manufacturer/supplier an article
 * was bought from, for filtering/reports. Nullable and additive — every
 * existing product (every non-footwear shop, and any shoe shop not using
 * the new article_series flow) is simply unset, no behavior change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('master_product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
