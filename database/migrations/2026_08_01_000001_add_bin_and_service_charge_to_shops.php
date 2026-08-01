<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('bin_no')->nullable()->after('receipt_footer');
            // null = service charge is off; a restaurant that wants one sets
            // a percent here, mirroring how vat_rate/turnover_rate already work
            $table->decimal('service_charge_rate', 5, 2)->nullable()->after('turnover_rate');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['bin_no', 'service_charge_rate']);
        });
    }
};
