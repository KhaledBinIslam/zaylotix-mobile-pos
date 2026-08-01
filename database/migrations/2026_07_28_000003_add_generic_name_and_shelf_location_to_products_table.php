<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy-oriented fields, but not pharmacy-exclusive at the schema level
 * (gated in the UI behind the same batch_tracking feature pharmacy/
 * supershop shops already have): `generic_name` lets a search for "Napa"
 * also surface other brands of the same active ingredient when the
 * specific brand is out of stock; `shelf_location` is where in the shop
 * the item physically sits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('generic_name')->nullable()->after('name_en');
            $table->string('shelf_location')->nullable()->after('generic_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['generic_name', 'shelf_location']);
        });
    }
};
