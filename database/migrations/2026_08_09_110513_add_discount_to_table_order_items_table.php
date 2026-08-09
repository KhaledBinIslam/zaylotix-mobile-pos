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
        Schema::table('table_order_items', function (Blueprint $table) {
            // per-item flat taka discount, same shape as SaleItem.discount —
            // stacks on top of the whole-bill discount in TableOrderController::bill()
            $table->decimal('discount', 10, 2)->default(0)->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_order_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
