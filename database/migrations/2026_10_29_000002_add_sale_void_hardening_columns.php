<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 39 (docs/FIX_PLAN.md) — three additive columns for hardening the
 * existing sale-void feature:
 *
 * - sales.promotion_ids: which Promotion row(s) this checkout actually
 *   bumped used_count for (coupon + BOGO triggers together). Before this,
 *   BOGO trigger ids were purely ephemeral (coupon_code only stores the
 *   string), so a void had no way to give those counters back. Null for
 *   every historical sale -- their void simply skips the counter reversal,
 *   same as today.
 *
 * - customers.advance_balance: store credit. Created when a bill is voided
 *   AFTER some of its due was already collected -- Khaled's explicit call
 *   (Phase 39 "জিজ্ঞেস করো" #0): that collected money becomes the
 *   customer's credit rather than a cash refund. Spent from the customer's
 *   due screen ("অগ্রিম সমন্বয়"), never negative, 0 for everyone today.
 *   Deliberately NOT negative-due: due:reconcile treats negative due as a
 *   corruption signal, so advance had to be its own column.
 *
 * - shops.void_time_limit_days: "বিল N দিনের বেশি পুরনো হলে ভয়েড করা যাবে
 *   না"। Null (default, every existing shop) = no limit -- behavior
 *   unchanged until an owner sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->json('promotion_ids')->nullable()->after('coupon_code');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('advance_balance', 12, 2)->default(0)->after('due');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedSmallInteger('void_time_limit_days')->nullable()->after('default_credit_limit');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('promotion_ids');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('advance_balance');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('void_time_limit_days');
        });
    }
};
