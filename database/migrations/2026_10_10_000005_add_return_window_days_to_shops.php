<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional owner-set "কত দিনের মধ্যে ফেরত নেওয়া যাবে" window, in days. NULL
 * (the default) means unlimited — every shop keeps today's unlimited-return
 * behavior until an owner explicitly sets a number. The owner can always
 * override past the window themselves (see ReturnController); this setting
 * only restricts non-owner staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('return_window_days')->nullable()->after('loyalty_point_value');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('return_window_days');
        });
    }
};
