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
        // Defaults to true -- signup has always been open, and every
        // existing test/environment assumes that. Khaled's ask to turn
        // it off right now is a deliberate one-time flip of this
        // platform's own row (via the new admin toggle), not a change
        // to what a fresh install starts with.
        Schema::table('site_settings', function (Blueprint $table) {
            $table->boolean('signup_enabled')->default(true)->after('play_store_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('signup_enabled');
        });
    }
};
