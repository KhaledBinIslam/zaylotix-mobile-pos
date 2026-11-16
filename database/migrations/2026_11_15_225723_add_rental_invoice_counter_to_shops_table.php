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
        Schema::table('shops', function (Blueprint $table) {
            // Its own counter, not shared with invoice_counter (INV-/WS-)
            // — a rental is a different kind of document and must never
            // skip/collide with a shop's regular sale invoice sequence.
            $table->unsignedBigInteger('rental_invoice_counter')->default(0)->after('invoice_counter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('rental_invoice_counter');
        });
    }
};
