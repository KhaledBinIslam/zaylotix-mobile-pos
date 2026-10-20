<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 feature 8 — "ক্রেডিট লিমিট" (see docs/FIX_PLAN.md's design note).
 * Reuses the existing customers.due/checkout-credit flow entirely — this
 * just adds a ceiling it's checked against. Both new columns are
 * nullable and default to null (= no limit, today's exact unlimited-due
 * behavior), so granting the feature changes nothing on its own until
 * the owner actually sets a number.
 *
 * `customers.credit_limit` overrides `shops.default_credit_limit` when
 * set; a customer with no override falls back to the shop-wide default,
 * which itself can be left unset for "no limit at all" even with the
 * feature on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('default_credit_limit', 12, 2)->nullable()->after('referral_bonus_points');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 12, 2)->nullable()->after('due');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('default_credit_limit');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
