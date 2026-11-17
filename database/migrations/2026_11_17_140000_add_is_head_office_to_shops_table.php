<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // A root/main shop that runs a real "Head Office" setup — central
            // accounting/reporting across every branch (see Business
            // Dashboard), but never rings up a sale itself; actual selling
            // happens only at its branches/warehouse. Same reasoning and
            // same shape as is_warehouse (checkout() blocks it, the Sell nav
            // tile hides it) — a separate flag because "head office" and
            // "warehouse" are different real-world roles (one is a selling
            // business's central office, the other is a stock depot), even
            // though both currently share the one behavior: no direct POS.
            // Only ever meaningful on a root shop (parent_shop_id null) —
            // nothing reads it on a branch row.
            $table->boolean('is_head_office')->default(false)->after('is_warehouse');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_head_office');
        });
    }
};
