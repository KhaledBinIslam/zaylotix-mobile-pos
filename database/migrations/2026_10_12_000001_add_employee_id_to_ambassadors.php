<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable at the DB level so an existing ambassador row (created before
 * this column existed) never breaks this migration -- required at the
 * application layer instead (see AmbassadorController::store()), per
 * Khaled's explicit ask: the "+ New ambassador" quick-add form is just
 * name + employee ID now, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassadors', function (Blueprint $table) {
            $table->string('employee_id')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('ambassadors', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
