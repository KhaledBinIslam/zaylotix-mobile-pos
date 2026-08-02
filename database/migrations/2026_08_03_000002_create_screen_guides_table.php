<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_guides', function (Blueprint $table) {
            $table->id();
            // free-text key a Vue page passes to <HowToHint screen-key="...">
            // -- not restricted to a fixed enum, so the admin can add a
            // guide for any screen/interface at any time, not just ones a
            // developer pre-registered
            $table->string('screen_key')->unique();
            // label only, for the admin's own list -- never shown to shop users
            $table->string('label');
            $table->text('text_bn');
            $table->text('text_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_guides');
    }
};
