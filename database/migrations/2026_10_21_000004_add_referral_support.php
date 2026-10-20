<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 feature 6 — "রেফারেল" (see docs/FIX_PLAN.md's design note). No
 * new reward mechanism: the bonus pays into the existing loyalty_points
 * balance (shops.loyalty_earn_rate's own column neighbor,
 * referral_bonus_points, default 50 — same "sensible preset, not a forced
 * zero" precedent as loyalty_earn_rate/loyalty_point_value). The two new
 * customers columns are the abuse-prevention guard this feature explicitly
 * needs: `referred_by_customer_id` records the relationship (self-
 * referential, set once at creation — see CustomerController::store(),
 * so a customer can never retroactively be made to "refer themselves"),
 * and `referral_rewarded_at` ensures the bonus is ever paid out exactly
 * once per referral, no matter how many times a checkout might replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('referred_by_customer_id')->nullable()->after('shop_id')->constrained('customers')->nullOnDelete();
            $table->timestamp('referral_rewarded_at')->nullable()->after('loyalty_points');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('referral_bonus_points')->default(50)->after('loyalty_point_value');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_customer_id');
            $table->dropColumn('referral_rewarded_at');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('referral_bonus_points');
        });
    }
};
