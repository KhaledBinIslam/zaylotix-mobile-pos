<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // Snapshot of which batch(es) BatchStock::deduct() actually took
            // this line's qty from — [{"batch_id":5,"qty":3},...]. Without
            // this, voiding a sale could only restore the *total* stock
            // count, never the specific batch, slowly drifting FEFO
            // accuracy every time a batch-tracked sale got voided.
            $table->json('batch_allocations')->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('batch_allocations');
        });
    }
};
