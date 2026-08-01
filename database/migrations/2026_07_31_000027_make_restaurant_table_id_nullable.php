<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_orders', function (Blueprint $table) {
            // a takeaway/parcel order has no physical table at all — forcing
            // the cashier to pick one just to start such an order was the
            // actual source of the confusion (see RestaurantTableController::openTakeaway)
            $table->foreignId('restaurant_table_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('table_orders', function (Blueprint $table) {
            $table->foreignId('restaurant_table_id')->nullable(false)->change();
        });
    }
};
