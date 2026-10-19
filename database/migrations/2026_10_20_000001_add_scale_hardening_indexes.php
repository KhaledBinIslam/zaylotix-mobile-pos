<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 scale-hardening audit (docs/SCALING_GUIDE.md) — four composite
 * indexes the audit found missing against queries that already run today:
 *   - customers(shop_id,due): CustomerController::index()'s due>0 list and
 *     HomeController::index()'s due sum/count — the latter runs on every
 *     single Home page view.
 *   - suppliers(shop_id,payable): SupplierController::index()'s payable>0
 *     list, same pattern.
 *   - product_batches(shop_id,expiry_date): ReportController's expiring-soon
 *     query and Reports::batchWiseReport() both filter/order by expiry_date
 *     within one shop; only a bare, shop_id-less index(expiry_date) existed.
 *   - table_orders(shop_id,status,opened_at): CdsController::index() filters
 *     status+opened_at within one shop, and fires every 8s per open CDS tab.
 * Purely additive — no existing data/behavior touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['shop_id', 'due']);
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->index(['shop_id', 'payable']);
        });
        Schema::table('product_batches', function (Blueprint $table) {
            $table->index(['shop_id', 'expiry_date']);
        });
        Schema::table('table_orders', function (Blueprint $table) {
            $table->index(['shop_id', 'status', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'due']);
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'payable']);
        });
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'expiry_date']);
        });
        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'status', 'opened_at']);
        });
    }
};
