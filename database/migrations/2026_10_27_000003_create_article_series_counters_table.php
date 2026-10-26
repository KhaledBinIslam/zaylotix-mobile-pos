<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/ARTICLE_SERIES_PLAN.md §2 — the article barcode's {SERIAL} segment
 * needs ONE counter per category shared across a main shop and every one
 * of its branches (confirmed by reading CatalogSync: ProductCategory rows
 * are NOT linked across shops today — each branch gets its own separate
 * row, matched by name on sync — so a counter column on ProductCategory
 * itself would NOT be shared the way the ticket requires). Always keyed
 * by the MAIN shop's id, regardless of which shop (main or branch)
 * actually creates the article — see App\Support\ArticleSerial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_series_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('category_code', 10);
            $table->unsignedInteger('next_serial')->default(10001);
            $table->timestamps();

            $table->unique(['main_shop_id', 'category_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_series_counters');
    }
};
