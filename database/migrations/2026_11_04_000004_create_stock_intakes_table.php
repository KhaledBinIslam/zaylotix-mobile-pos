<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of goods coming INTO stock, in taka.
 *
 * Khaled: "koto tk er mal utche utteche ey ammount jate vul na dekhai
 * ... kon serial e koto tk er maal utche ... details e report paowa
 * jai". Stock quantities were always tracked, but the MONEY walking in
 * the door was never written anywhere — it could only be reconstructed
 * from current stock, which forgets everything already sold. A report
 * that cannot be wrong needs its own ledger, written in the same
 * transaction as the stock movement itself: if this row can't be
 * written, the intake doesn't happen either.
 *
 * Rows are never updated or deleted — a correction is an operational
 * matter (adjust stock), the history of what was recorded stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            // article_create | variant_add | variant_stock_in | product_stock_in | product_create
            $table->string('source', 30);
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_intakes');
    }
};
