<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A shop-chosen line printed at the bottom of every memo, replacing the hardcoded "ধন্যবাদ! আবার আসবেন 🙏" — null keeps that same default (see Sales/Show.vue). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('receipt_footer', 255)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('receipt_footer');
        });
    }
};
