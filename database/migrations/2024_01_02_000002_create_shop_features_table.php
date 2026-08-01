<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which features a given shop has been granted. Presence = enabled. */
    public function up(): void
    {
        Schema::create('shop_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_features');
    }
};
