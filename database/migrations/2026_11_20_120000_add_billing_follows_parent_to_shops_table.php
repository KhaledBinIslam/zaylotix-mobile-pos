<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Opt-in only (default false — zero change for every existing
            // branch, which keeps billing exactly as independent as it is
            // today). When true on a branch, its effective subscription
            // status/expiry/plan resolve to its parent shop's row instead
            // of its own — for a client like Baby Park who pays one
            // combined subscription covering both of their shops, so the
            // admin only ever has to renew one row, not remember both.
            $table->boolean('billing_follows_parent')->default(false)->after('parent_shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('billing_follows_parent');
        });
    }
};
