<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // snapshot of which unit was actually sold (piece/strip/box) and
            // its conversion factor, so historical receipts/reports stay
            // accurate even if the product's pack sizes change later
            $table->string('unit_label')->nullable()->after('product_name');
            $table->unsignedInteger('unit_factor')->default(1)->after('unit_label');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['unit_label', 'unit_factor']);
        });
    }
};
