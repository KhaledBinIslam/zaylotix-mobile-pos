<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog of togglable capabilities (admin-managed). A shop only gets
     * access to a capability if it's attached via `shop_features` — new
     * features can be added here later without touching shop code, and
     * existing shops simply won't have them until an admin grants them.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g. memo_whatsapp, barcode_printing
            $table->string('label_bn');
            $table->string('label_en');
            $table->string('category')->default('general'); // billing, printing, inventory, ...
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true); // admin can retire a feature without deleting history
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
