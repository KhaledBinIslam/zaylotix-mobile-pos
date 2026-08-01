<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many days before a shop's subscription/trial expires the daily
 * `zaylotix:payment-reminders` job starts warning them — was a hardcoded
 * `REMINDER_DAYS` constant, now admin-configurable from the site settings
 * page instead of requiring a code change to adjust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedInteger('reminder_days')->default(3)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('reminder_days');
        });
    }
};
