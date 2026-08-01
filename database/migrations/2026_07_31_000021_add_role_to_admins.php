<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            // 'support' can view shops, impersonate for troubleshooting, and
            // read system health/analytics, but can't delete a shop, manage
            // business types/features, or manage other admin accounts —
            // every existing admin becomes 'super_admin' below so today's
            // single-admin setup keeps its full access unchanged.
            $table->enum('role', ['super_admin', 'support'])->default('super_admin')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
