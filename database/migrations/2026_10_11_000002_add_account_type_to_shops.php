<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "paid" is the default (not "trial") so every existing shop lands exactly
 * where Dashboard/Analytics already counted it today — zero change to
 * current revenue/shop-count numbers on deploy. Khaled reclassifies
 * demo/trial/internal shops himself afterward; new shops get a sensible
 * default from whichever flow creates them (see ShopProvisioner call sites).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->enum('account_type', ['demo', 'trial', 'paid', 'internal'])->default('paid')->after('plan');
            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex(['account_type']);
            $table->dropColumn('account_type');
        });
    }
};
