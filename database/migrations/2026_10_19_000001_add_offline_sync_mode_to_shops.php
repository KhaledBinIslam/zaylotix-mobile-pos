<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'auto' = existing behavior (sync as soon as back online, no
        // change for any existing shop); 'review' = owner opt-in, see
        // docs/FIX_PLAN.md Phase 12. Plain string, not a DB-level enum —
        // matches this app's existing convention (e.g. sales.payment_mode,
        // shops.vat_mode) of validating the allowed values in PHP/JS
        // rather than the schema, which is what already lets sqlite (used
        // by the test suite) and MySQL (production) share one migration.
        Schema::table('shops', function (Blueprint $table) {
            $table->string('offline_sync_mode')->default('auto')->after('mobile_nav_items');
        });

        DB::table('shops')->whereNull('offline_sync_mode')->update(['offline_sync_mode' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('offline_sync_mode');
        });
    }
};
