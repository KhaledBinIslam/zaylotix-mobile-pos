<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Generates a portable, standalone `.sql` dump of exactly one shop's data —
 * real INSERT statements (not a description of the data, the actual rows,
 * primary keys and foreign keys intact) so a shop that wants to leave the
 * platform (or just wants an offline copy) can hand this file to any fresh
 * install of the same schema (`php artisan migrate`) and `mysql < dump.sql`
 * their way back to exactly where they were. This is deliberately NOT the
 * same thing as ShopDataExport (the Excel workbook) — that's for humans to
 * read; this is for machines to import.
 *
 * Tables are dumped in FK-dependency order (parents before children) and
 * wrapped in a single transaction with foreign key checks briefly disabled,
 * so a partial/out-of-order import can never leave the target database in
 * a half-restored state.
 */
class ShopSqlDump
{
    /**
     * Tenant-owned tables, in an order that never inserts a child before its
     * parent. Every time a new tenant-owned table is added to the schema
     * (a new migration with a `shop_id` column), it must be added here too
     * — this list is NOT auto-discovered, so a forgotten entry silently
     * drops that table from every shop's export with no error anywhere.
     */
    private const TABLES = [
        'product_categories',
        'units',
        'products',
        'product_units',
        'product_batches',   // -> products
        'product_variants',  // -> products
        'product_serials',   // -> products
        'suppliers',
        'customers',
        'sales',              // -> users (voided_by), already dumped above
        'sale_items',          // -> sales, product_variants, product_serials
        'sale_payments',      // -> sales
        'payments',
        'purchases',          // -> suppliers, products
        'expense_categories',
        'expenses',
        'damages',
        'returns',
        'supplier_returns',   // -> suppliers, products
        'stock_counts',
        'activity_logs',      // -> users, already dumped above
        'restaurant_tables',
        'table_orders',        // -> restaurant_tables, sales
        'table_order_items',   // -> table_orders, products
        'held_carts',
        'promotions',          // -> products (buy_product_id, get_product_id)
        'quotations',           // -> customers, sales
        'quotation_items',      // -> quotations, products
    ];

    public static function generate(Shop $shop): string
    {
        $lines = [];
        $lines[] = '-- Zaylotix POS — standalone data export for shop #'.$shop->id.' ('.$shop->name.')';
        $lines[] = '-- Generated '.now()->toIso8601String();
        $lines[] = '-- Import into a fresh install of the same schema: run `php artisan migrate` first, then `mysql <db> < this-file.sql`.';
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $lines[] = 'START TRANSACTION;';
        $lines[] = '';

        // 1. business_types row this shop references (parent of shops.business_type_id)
        if ($shop->business_type_id) {
            $lines[] = self::dumpRows('business_types', DB::table('business_types')->where('id', $shop->business_type_id)->get());
        }

        // 2. the shop row itself
        $lines[] = self::dumpRows('shops', DB::table('shops')->where('id', $shop->id)->get());

        // 3. its users (owner + cashier, if any)
        $lines[] = self::dumpRows('users', DB::table('users')->where('shop_id', $shop->id)->get());

        // 4. features this shop was granted (parent of shop_features.feature_id) + the pivot itself
        $featureIds = DB::table('shop_features')->where('shop_id', $shop->id)->pluck('feature_id');
        if ($featureIds->isNotEmpty()) {
            $lines[] = self::dumpRows('features', DB::table('features')->whereIn('id', $featureIds)->get());
        }
        $lines[] = self::dumpRows('shop_features', DB::table('shop_features')->where('shop_id', $shop->id)->get());

        // 5. every tenant-owned business table, in FK-safe order
        foreach (self::TABLES as $table) {
            $lines[] = self::dumpRows($table, DB::table($table)->where('shop_id', $shop->id)->get());
        }

        $lines[] = 'COMMIT;';
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode("\n", array_filter($lines, fn ($l) => $l !== ''))."\n";
    }

    private static function dumpRows(string $table, $rows): string
    {
        if ($rows->isEmpty()) {
            return '';
        }

        $pdo = DB::connection()->getPdo();
        $columns = array_keys((array) $rows->first());
        $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

        $valueLines = $rows->map(function ($row) use ($pdo, $columns) {
            $values = array_map(function ($col) use ($row, $pdo) {
                $value = $row->{$col};

                return match (true) {
                    is_null($value) => 'NULL',
                    is_int($value) || is_float($value) => (string) $value,
                    default => $pdo->quote((string) $value),
                };
            }, $columns);

            return '('.implode(', ', $values).')';
        })->implode(",\n");

        return "-- {$table} (".$rows->count().' row'.($rows->count() === 1 ? '' : 's').")\n"
            ."INSERT INTO `{$table}` ({$columnList}) VALUES\n{$valueLines};\n";
    }
}
