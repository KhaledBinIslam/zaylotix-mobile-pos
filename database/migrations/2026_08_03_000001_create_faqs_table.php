<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            // platform-wide, not shop-scoped -- one FAQ list every shop
            // user sees, managed centrally by the admin, same as Feature/
            // SiteSetting rather than a per-shop tenant-owned table
            $table->string('question_bn');
            $table->string('question_en');
            $table->text('answer_bn');
            $table->text('answer_en');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
