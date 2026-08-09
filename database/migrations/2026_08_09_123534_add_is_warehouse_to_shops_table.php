<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // labeling only -- a warehouse is otherwise a completely normal
            // branch (own Shop row, own independent stock, cloned catalog
            // via CatalogSync) so every existing branch code path (switching,
            // staff being fixed to it, stock transfers) works unchanged.
            // Purely so the UI can show a warehouse icon and, if the owner
            // wants, keep it out of the POS-facing branch switcher later.
            $table->boolean('is_warehouse')->default(false)->after('parent_shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_warehouse');
        });
    }
};
