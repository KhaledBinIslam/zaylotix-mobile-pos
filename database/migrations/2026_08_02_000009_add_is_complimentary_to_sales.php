<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // separate from payment_mode -- a comp'd sale still needs
            // *some* payment_mode value (it naturally lands on 'cash' once
            // discount forces total to 0, see PosController/TableOrderController),
            // this flag is what actually distinguishes "given away free" from
            // a real fully-discounted sale for reporting purposes
            $table->boolean('is_complimentary')->default(false)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('is_complimentary');
        });
    }
};
