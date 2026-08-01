<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant vertical additions: split-bill, table merge, and waiter tracking.
 *
 *  - sales.table_order_id: lets ONE table order produce MANY sales (a
 *    split bill), replacing the old assumption baked into
 *    table_orders.sale_id (a single nullable FK, 1:1) that a table order
 *    becomes exactly one sale. table_orders.sale_id is kept, untouched,
 *    as "the sale that finally closed this order" for full backward
 *    compatibility; every sale born from a table order — split or not —
 *    now also gets table_order_id, and Sale::tableOrder() reads from that
 *    instead. Existing historical sales are backfilled from the old
 *    relationship so old receipts keep working exactly as before.
 *  - table_order_items.sale_id: which specific sale (if any) an item was
 *    actually billed into — null means "still open, not yet billed".
 *    A split bill only touches the items it's given; whatever's left with
 *    a null sale_id is still the table's running tab. The order itself
 *    only fully closes (status=billed, table freed) once every item has
 *    a sale_id.
 *  - table_orders.status gets a 'merged' value: when table A's order is
 *    folded into table B's (RestaurantTableController::merge), A's order
 *    row is marked 'merged' rather than 'cancelled' (no stock was reversed)
 *    or 'billed' (it wasn't sold, its items just moved to B's order).
 *  - table_orders.waiter_name: free-text, same spirit as kitchen_note —
 *    which staff member is serving this table, for a waiter-wise sales
 *    breakdown later. Not a `users` FK: most shops here don't create a
 *    login per waiter (only one cashier account exists by design), so this
 *    is just a name jotted down for accountability, not an authentication
 *    identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('table_order_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::table('table_order_items', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('cost')->constrained()->nullOnDelete();
        });

        Schema::table('table_orders', function (Blueprint $table) {
            $table->string('waiter_name')->nullable()->after('kitchen_note');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE table_orders MODIFY status ENUM('open','billed','cancelled','merged') DEFAULT 'open'");
            // backfill every pre-existing 1:1 sale<->table-order link into the new column
            DB::statement('UPDATE sales s JOIN table_orders t ON t.sale_id = s.id SET s.table_order_id = t.id');
            // and every already-billed order's items were, by definition, billed into that one sale
            DB::statement('UPDATE table_order_items ti JOIN table_orders t ON t.id = ti.table_order_id SET ti.sale_id = t.sale_id WHERE t.sale_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropColumn('waiter_name');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE table_orders MODIFY status ENUM('open','billed','cancelled') DEFAULT 'open'");
        }

        Schema::table('table_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('table_order_id');
        });
    }
};
