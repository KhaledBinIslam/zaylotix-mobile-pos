<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Online Business" module, part 5 — see docs/FIX_PLAN.md's Phase 35
 * design note. All three nullable — every existing expense row is
 * unaffected; these are only ever filled in for an ad-spend entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('campaign_tag')->nullable()->after('memo');
            $table->decimal('amount_usd', 12, 2)->nullable()->after('amount');
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('amount_usd');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['campaign_tag', 'amount_usd', 'exchange_rate']);
        });
    }
};
