<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 "full Wholesale system", part B — wholesale customer groups
 * (see docs/FIX_PLAN.md's design note). A binary flag, not a multi-tier
 * customer-group hierarchy — auto-defaults the POS sale_type toggle once
 * this customer is attached, still manually overridable. A customer
 * without this flag behaves exactly as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_wholesale')->default(false)->after('no_marketing');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_wholesale');
        });
    }
};
