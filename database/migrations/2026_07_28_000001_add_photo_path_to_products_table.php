<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional real product photo — separate from `emoji`, which stays as the
 * always-available fallback icon. A shop can add photos to some products
 * and not others; the UI shows the photo when set, the emoji otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('emoji');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
