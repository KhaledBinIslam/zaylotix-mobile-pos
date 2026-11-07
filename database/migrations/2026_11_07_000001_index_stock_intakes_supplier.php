<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khaled's supplier report (date-range purchase-price breakdown) groups
 * stock_intakes by supplier_id within a date window — this table only had
 * (shop_id, created_at) and (shop_id, product_id) indexed before, so that
 * query scanned every intake row for the shop. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_intakes', function (Blueprint $table) {
            $table->index(['shop_id', 'supplier_id', 'created_at'], 'stock_intakes_shop_supplier_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_intakes', function (Blueprint $table) {
            $table->dropIndex('stock_intakes_shop_supplier_created_idx');
        });
    }
};
