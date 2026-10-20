<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 3 feature 10 — "অ্যাম্বাসেডর টুলস" (see docs/FIX_PLAN.md's design
 * note, scope confirmed with Khaled as just the referral code + signup
 * link piece — a full self-serve ambassador portal with its own login
 * was explicitly ruled out as too large for this phase). Nullable/unique;
 * generated once at AmbassadorController::store() time, so every
 * ambassador created from here on has one automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassadors', function (Blueprint $table) {
            $table->string('referral_code')->nullable()->unique()->after('employee_id');
        });

        // Backfill every ambassador created before this migration — every
        // NEW one gets a code at AmbassadorController::store() time, but an
        // existing ambassador would otherwise have no signup link at all.
        $existing = DB::table('ambassadors')->whereNull('referral_code')->get(['id', 'employee_id']);
        $used = [];
        foreach ($existing as $row) {
            $base = Str::upper(Str::slug($row->employee_id, '')) ?: 'AMB';
            $code = $base;
            while (in_array($code, $used, true) || DB::table('ambassadors')->where('referral_code', $code)->exists()) {
                $code = $base.Str::upper(Str::random(3));
            }
            $used[] = $code;
            DB::table('ambassadors')->where('id', $row->id)->update(['referral_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('ambassadors', function (Blueprint $table) {
            $table->dropColumn('referral_code');
        });
    }
};
