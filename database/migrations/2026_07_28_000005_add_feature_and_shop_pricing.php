<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-wise pricing: each Feature can carry its own monthly price, so
 * the admin's Create/Edit Shop screen can show a running suggested total as
 * features are ticked — the actual price charged to a shop (`shops.
 * monthly_fee`) is still a separate, admin-editable number (real-world
 * pricing involves negotiation/discounts), just pre-filled from that sum
 * rather than picked out of thin air.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->decimal('monthly_price', 10, 2)->default(0)->after('description');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->decimal('monthly_fee', 10, 2)->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('monthly_price');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('monthly_fee');
        });
    }
};
