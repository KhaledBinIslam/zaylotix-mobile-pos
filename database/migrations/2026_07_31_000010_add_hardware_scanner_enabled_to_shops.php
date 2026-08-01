<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner-configurable — whether a USB/Bluetooth keyboard-wedge barcode scanner should be listened for on the POS page, independent of the existing camera-scan (`sales_mode`) toggle. Defaults true: passively listening for fast-typed input is harmless even if the shop never actually has a hardware scanner plugged in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('hardware_scanner_enabled')->default(true)->after('sales_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('hardware_scanner_enabled');
        });
    }
};
