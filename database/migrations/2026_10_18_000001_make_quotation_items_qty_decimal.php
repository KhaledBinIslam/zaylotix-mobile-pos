<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            // a weighed product (kg/litre/metre) can now take a fractional
            // qty on a quotation, same as it already can at checkout/damage/
            // return/stock-count — every existing whole-number row round-trips
            // unchanged through this widen.
            $table->decimal('qty', 12, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->integer('qty')->change();
        });
    }
};
