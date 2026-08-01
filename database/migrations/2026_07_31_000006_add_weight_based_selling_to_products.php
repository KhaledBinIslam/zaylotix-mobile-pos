<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weight/volume-based selling for grocery (মুদি দোকান) — a mudi dokan sells
 * most of its catalog loose: চাল/ডাল/তেল by কেজি/লিটার, not by the piece.
 * Before this, every qty column here was a plain integer, so there was no
 * way to record selling 250g of dal or half a litre of oil at all.
 *
 * `sold_by_weight` + `weight_unit` (kg/litre) on products marks a product as
 * loose-sold: its price is interpreted as "per kg"/"per litre", and its
 * stock is now a real decimal quantity in that base unit (so e.g. 0.25 kg is
 * exactly 250g on hand, not rounded to 0). A product with neither flag set
 * behaves exactly as before — this is purely additive, every existing
 * product defaults to sold_by_weight=false and keeps whole-unit stock.
 *
 * The qty/stock columns below widen from integer to a 3-decimal-place
 * number everywhere a quantity of a *product* is recorded, so a weighed
 * line's fractional amount survives checkout, stock-in, damage, return, and
 * stock-count without being silently truncated. Deliberately NOT touched:
 * product_batches.qty (pharmacy FEFO — batches are a separate, still-integer
 * vertical layer), product_variants.stock (clothing sizes are always whole
 * units), product_serials (electronics units are always whole), and
 * table_order_items.qty (restaurant plates/portions are always whole) —
 * none of those verticals sell loose/weighed goods.
 *
 * MySQL supports widening an existing column via a raw MODIFY; SQLite (used
 * for the test suite) has no MODIFY COLUMN at all, but its dynamic type
 * affinity already stores a bound float value as REAL even in an
 * INTEGER-affinity column (affinity conversion only rewrites *text* input,
 * never a value the driver already sends as a float) — so on sqlite this is
 * a correctness no-op, not a workaround needed for tests to pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sold_by_weight')->default(false)->after('unit_id');
            $table->enum('weight_unit', ['kg', 'litre'])->nullable()->after('sold_by_weight');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY stock DECIMAL(12,3) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE sale_items MODIFY qty DECIMAL(12,3) NOT NULL');
            DB::statement('ALTER TABLE damages MODIFY qty DECIMAL(12,3) NOT NULL');
            DB::statement('ALTER TABLE returns MODIFY qty DECIMAL(12,3) NOT NULL');
            DB::statement('ALTER TABLE purchases MODIFY qty DECIMAL(12,3) NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE purchases MODIFY qty INT UNSIGNED NULL');
            DB::statement('ALTER TABLE returns MODIFY qty INT NOT NULL');
            DB::statement('ALTER TABLE damages MODIFY qty INT NOT NULL');
            DB::statement('ALTER TABLE sale_items MODIFY qty INT NOT NULL');
            DB::statement('ALTER TABLE products MODIFY stock INT NOT NULL DEFAULT 0');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sold_by_weight', 'weight_unit']);
        });
    }
};
