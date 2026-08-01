<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            // Independent of invested_amount — real partnerships often
            // negotiate a split that isn't strictly proportional to capital
            // put in, so this is a separate, manually-set number.
            $table->decimal('ownership_percent', 5, 2);
            $table->decimal('invested_amount', 14, 2)->default(0);
            $table->decimal('withdrawn_amount', 14, 2)->default(0);
            $table->date('joined_date');
            $table->timestamps();

            $table->index('shop_id');
        });

        Schema::create('partner_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            // 'investment' = partner puts more money into the shop;
            // 'withdrawal' = partner draws out their capital/profit share.
            $table->enum('type', ['investment', 'withdrawal']);
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'bank']);
            $table->string('note', 255)->nullable();
            $table->date('date');
            $table->timestamps();

            $table->index(['shop_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_transactions');
        Schema::dropIfExists('partners');
    }
};
