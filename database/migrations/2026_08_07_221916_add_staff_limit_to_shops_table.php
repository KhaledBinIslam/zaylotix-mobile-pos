<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // null = use the plan default (StaffController::DEFAULT_STAFF_CAP,
            // or unlimited if the shop has the `unlimited_staff` feature) —
            // set explicitly here only when admin negotiates a custom cashier
            // count for a specific shop regardless of its feature tier.
            $table->unsignedInteger('staff_limit')->nullable()->after('monthly_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('staff_limit');
        });
    }
};
