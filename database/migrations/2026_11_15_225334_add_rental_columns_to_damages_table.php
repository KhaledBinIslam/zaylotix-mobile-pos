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
        Schema::table('damages', function (Blueprint $table) {
            // So the Rental dashboard's "Damaged Products" tile can query
            // rental-caused damage specifically, instead of parsing the
            // free-text `reason` column.
            $table->foreignId('rental_id')->nullable()->after('product_batch_id')->constrained()->nullOnDelete();
            $table->foreignId('rental_item_id')->nullable()->after('rental_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_item_id');
            $table->dropConstrainedForeignId('rental_id');
        });
    }
};
