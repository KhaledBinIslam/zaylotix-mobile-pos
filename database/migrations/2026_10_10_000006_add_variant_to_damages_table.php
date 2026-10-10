<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A returned variant item marked "damaged" (see ReturnController::process)
 * logs a Damage row instead of restocking — without this column that row
 * would lose which size/color was actually damaged. Nullable: every other
 * damage-entry path (DamageController, which still rejects variant products
 * outright) never sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');
        });
    }
};
