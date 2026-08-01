<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `requires_prescription` flags a drug-control-sensitive product so the POS
 * screen reminds the cashier to check for one at checkout — this app has no
 * way to digitally verify a prescription, so it's a reminder/record, not an
 * enforced block. `prescription_note` on sales is the free-text record of
 * what was checked (doctor, medicine, dosage), captured at checkout time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_prescription')->default(false)->after('shelf_location');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->text('prescription_note')->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('requires_prescription');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('prescription_note');
        });
    }
};
