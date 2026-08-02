<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            // mutually exclusive with product_id (see PurchaseController) --
            // buying a raw ingredient reuses the exact same supplier/cash/
            // bank/credit-payable flow a product purchase already has,
            // rather than a parallel ingredient-purchase system
            $table->foreignId('ingredient_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
        });
    }
};
