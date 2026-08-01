<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale (পাইকারি) selling — a product may optionally have its own
 * wholesale_price alongside the normal retail `price`; a whole checkout is
 * marked retail or wholesale (sales.sale_type), matching how a small
 * pharmacy/grocery actually sells — a bulk-buying customer gets the
 * wholesale rate on everything in that one transaction, not item-by-item.
 * A product with no wholesale_price set simply always sells at its normal
 * price regardless of sale_type — this is purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('wholesale_price', 12, 2)->nullable()->after('price');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->enum('sale_type', ['retail', 'wholesale'])->default('retail')->after('payment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('sale_type');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('wholesale_price');
        });
    }
};
