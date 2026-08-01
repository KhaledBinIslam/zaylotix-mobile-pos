<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty rate is owner-configurable, not hardcoded — different shops want
 * very different earn/redeem economics (a pharmacy's margins tolerate a
 * different giveaway rate than a supershop's). Two numbers cover it:
 *  - loyalty_earn_rate: points earned per 100 taka actually paid (after all
 *    discounts) — e.g. 1.00 = 1 point per 100tk spent.
 *  - loyalty_point_value: taka a single point is worth when redeemed at a
 *    later checkout — e.g. 1.00 = 1 point knocks off 1 taka.
 * Both default to a sane 1:1-ish starting point an owner can then tune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('loyalty_earn_rate', 8, 2)->default(1)->after('turnover_rate');
            $table->decimal('loyalty_point_value', 8, 2)->default(1)->after('loyalty_earn_rate');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points')->default(0)->after('due');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedInteger('points_earned')->nullable()->after('coupon_code');
            $table->unsignedInteger('points_redeemed')->nullable()->after('points_earned');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['points_earned', 'points_redeemed']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('loyalty_points');
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['loyalty_earn_rate', 'loyalty_point_value']);
        });
    }
};
