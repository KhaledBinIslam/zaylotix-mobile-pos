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
        // The landing page's "App Download" section's Play Store button —
        // left null until Khaled has actually created the Play Console
        // listing, same singleton row as the other platform-wide settings.
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('play_store_url')->nullable()->after('whatsapp_contact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('play_store_url');
        });
    }
};
