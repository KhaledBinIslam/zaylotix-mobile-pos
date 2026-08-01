<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // per-line discount (flat taka amount), independent of the
            // sale-wide `discount` on the `sales` table — a cashier can give
            // ৳5 off one item and still apply an overall discount at checkout
            $table->decimal('discount', 10, 2)->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
