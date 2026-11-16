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
        Schema::table('products', function (Blueprint $table) {
            // 'sell_only' default — every existing product everywhere keeps
            // selling exactly as it does today. Only a shop with the
            // 'rentals' feature ever sets this to anything else.
            $table->string('rental_mode')->default('sell_only')->after('stock'); // sell_only, rent_only, both
            $table->decimal('rent_price', 10, 2)->nullable()->after('rental_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['rental_mode', 'rent_price']);
        });
    }
};
