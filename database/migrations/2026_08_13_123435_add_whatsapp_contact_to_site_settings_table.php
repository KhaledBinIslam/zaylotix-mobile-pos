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
        // The public landing page's "WhatsApp" button (contact Zaylotix
        // itself about signing up) — platform-wide, not per-shop, same
        // singleton row as the logo/reminder_days settings.
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('whatsapp_contact')->nullable()->after('reminder_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('whatsapp_contact');
        });
    }
};
