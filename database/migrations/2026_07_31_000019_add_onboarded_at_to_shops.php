<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('receipt_footer');
        });

        // Backfill every shop that already existed before this feature
        // shipped so the first-login setup wizard only ever appears for
        // genuinely new shops, never surprises an existing owner mid-use.
        DB::table('shops')->whereNull('onboarded_at')->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
