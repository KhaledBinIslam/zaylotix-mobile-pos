<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 4 — see docs/FIX_PLAN.md's Phase 34
 * design note. A plain free-text field the owner types per return event
 * — satisfies "editable return reasons" without a managed reasons-list
 * CRUD nothing asked for beyond the phrase itself. Benefits every
 * return through ReturnController::process(), not just online ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->string('reason')->nullable()->after('condition');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
