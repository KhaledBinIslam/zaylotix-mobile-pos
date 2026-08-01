<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_order_items', function (Blueprint $table) {
            // distinct from served_at (waiter physically brought it to the
            // table, tracked separately in TableOrderController) -- this is
            // the kitchen's own "finished cooking it" moment, what the KDS
            // screen actually marks when a cook taps "Cook"/"Cook All"
            $table->timestamp('cooked_at')->nullable()->after('kot_printed_at');
        });
    }

    public function down(): void
    {
        Schema::table('table_order_items', function (Blueprint $table) {
            $table->dropColumn('cooked_at');
        });
    }
};
