<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The manufacturer/company name — pharmacy shops filter/browse by this constantly (e.g. "সব Square-এর ওষুধ দেখাও"), alongside the existing generic_name for the same "browse by classification, not just by product name" need. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('company')->nullable()->after('generic_name');
            $table->index(['shop_id', 'company']);
            $table->index(['shop_id', 'generic_name']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'company']);
            $table->dropIndex(['shop_id', 'generic_name']);
            $table->dropColumn('company');
        });
    }
};
