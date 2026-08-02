<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // a branch shop points to its business's main shop -- null means
            // this shop IS a main shop (every shop today, unchanged). See
            // Tenancy::id()'s branch-switch check and Admin\ShopController's
            // branch-creation action.
            $table->foreignId('parent_shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_shop_id');
        });
    }
};
