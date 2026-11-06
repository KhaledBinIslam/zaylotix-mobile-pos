<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEAMAN Phase 6 — the importer parks every hand-written "VERIFY: ..."
 * remark from the owner's paper list on the product itself, so the owner
 * fixes prices/spellings in the app instead of hunting through a CSV.
 * Internal only: never printed on labels, memos or any customer copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('internal_note')->nullable()->after('search_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('internal_note');
        });
    }
};
