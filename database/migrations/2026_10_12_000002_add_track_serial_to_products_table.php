<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // per-product opt-in on top of the shop-wide serial_tracking
            // feature — lets a mobile shop track IMEIs on phones without
            // the stock-in form also demanding one for every phone case
            $table->boolean('track_serial')->default(false)->after('sold_by_weight');
        });

        // a product that already has serial records (stocked in before
        // this column existed) must keep showing the IMEI field after
        // deploy, or an owner mid-way through serializing their phone
        // inventory would suddenly lose the input with no way to add more
        DB::table('products')->whereIn('id', function ($query) {
            $query->select('product_id')->distinct()->from('product_serials');
        })->update(['track_serial' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('track_serial');
        });
    }
};
