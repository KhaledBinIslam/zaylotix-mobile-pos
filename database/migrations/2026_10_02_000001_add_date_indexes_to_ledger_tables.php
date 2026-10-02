<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production-readiness audit: payments/purchases/expenses/damages/returns/
 * stock_counts only indexed bare shop_id, despite every report/listing
 * screen filtering by a date range on top of shop — purely additive,
 * doesn't touch any existing data.
 */
return new class extends Migration
{
    private const TABLES = ['payments', 'purchases', 'expenses', 'damages', 'returns', 'stock_counts'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['shop_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['shop_id', 'date']);
            });
        }
    }
};
