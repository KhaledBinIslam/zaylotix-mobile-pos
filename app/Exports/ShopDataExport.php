<?php

namespace App\Exports;

use App\Models\Shop;
use App\Support\Tenancy;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Pulls one shop's entire dataset out as its own portable workbook — one
 * sheet per table — so admin can hand a shop owner a standalone copy of
 * their data (e.g. if they leave the platform, or just want an offline
 * backup) without touching any other shop's rows.
 *
 * Every tenant-owned table needs an entry in both $sheets and $models below
 * — like ShopSqlDump::TABLES, this list is NOT auto-discovered, so a new
 * table added to the schema silently has no Excel sheet until it's added
 * here too.
 */
class ShopDataExport implements WithMultipleSheets
{
    public function __construct(private Shop $shop) {}

    public function sheets(): array
    {
        Tenancy::set($this->shop->id);

        $sheets = [
            'Categories' => ['name', 'name_en', 'emoji'],
            'Units' => ['name', 'name_en', 'code'],
            'Products' => ['name', 'name_en', 'barcode', 'cost', 'price', 'discount_price', 'stock', 'reorder_point', 'expiry_date', 'batch_no', 'size', 'color', 'imei'],
            'ProductUnits' => ['product_id', 'unit_id', 'factor', 'price'],
            'ProductBatches' => ['product_id', 'batch_no', 'expiry_date', 'qty', 'cost'],
            'ProductVariants' => ['product_id', 'size', 'color', 'barcode', 'stock', 'price', 'cost'],
            'ProductSerials' => ['product_id', 'imei', 'warranty_expiry', 'status', 'cost'],
            'Suppliers' => ['name', 'phone', 'address', 'payable'],
            'Customers' => ['name', 'phone', 'due', 'total_spent', 'visits', 'loyalty_points'],
            'Sales' => ['invoice_no', 'date', 'time', 'subtotal', 'discount', 'coupon_code', 'points_earned', 'points_redeemed', 'vat', 'total', 'profit', 'payment_mode', 'voided_at', 'voided_reason'],
            'SaleItems' => ['sale_id', 'product_name', 'variant_label', 'unit_label', 'qty', 'price', 'discount', 'cost'],
            'SalePayments' => ['sale_id', 'method', 'amount'],
            'Payments' => ['customer_id', 'user_id', 'amount', 'method', 'date'],
            'Purchases' => ['supplier', 'supplier_id', 'memo', 'amount', 'method', 'product_id', 'qty', 'date'],
            'ExpenseCategories' => ['name', 'name_en'],
            'Expenses' => ['expense_category_id', 'memo', 'amount', 'method', 'date'],
            'Damages' => ['product_id', 'qty', 'reason', 'loss', 'date'],
            'Returns' => ['product_id', 'user_id', 'qty', 'refund', 'phone', 'date'],
            'SupplierReturns' => ['supplier_id', 'supplier', 'product_id', 'qty', 'reason', 'settlement_method', 'amount', 'date'],
            'Quotations' => ['customer_id', 'customer_name', 'customer_phone', 'quote_no', 'date', 'valid_until', 'status', 'subtotal', 'discount', 'total', 'notes', 'sale_id'],
            'QuotationItems' => ['quotation_id', 'product_id', 'product_name', 'qty', 'price', 'discount'],
            'StockCounts' => ['date', 'changed', 'changes'],
            'ActivityLog' => ['user_id', 'action', 'description', 'created_at'],
            'RestaurantTables' => ['name', 'status'],
            'TableOrders' => ['restaurant_table_id', 'status', 'order_source', 'delivery_platform', 'kitchen_note', 'customer_name', 'sale_id', 'opened_at'],
            'TableOrderItems' => ['table_order_id', 'product_name', 'qty', 'price', 'cost', 'served_at'],
            'HeldCarts' => ['label', 'cart_data', 'created_at'],
            'Promotions' => ['name', 'type', 'active', 'code', 'discount_type', 'discount_value', 'min_purchase', 'usage_limit', 'buy_product_id', 'buy_qty', 'get_product_id', 'get_qty', 'get_discount_percent', 'used_count', 'starts_at', 'expires_at'],
        ];

        $models = [
            'Categories' => \App\Models\ProductCategory::class,
            'Units' => \App\Models\Unit::class,
            'Products' => \App\Models\Product::class,
            'ProductUnits' => \App\Models\ProductUnit::class,
            'ProductBatches' => \App\Models\ProductBatch::class,
            'ProductVariants' => \App\Models\ProductVariant::class,
            'ProductSerials' => \App\Models\ProductSerial::class,
            'Suppliers' => \App\Models\Supplier::class,
            'Customers' => \App\Models\Customer::class,
            'Sales' => \App\Models\Sale::class,
            'SaleItems' => \App\Models\SaleItem::class,
            'SalePayments' => \App\Models\SalePayment::class,
            'Payments' => \App\Models\Payment::class,
            'Purchases' => \App\Models\Purchase::class,
            'ExpenseCategories' => \App\Models\ExpenseCategory::class,
            'Expenses' => \App\Models\Expense::class,
            'Damages' => \App\Models\Damage::class,
            'Returns' => \App\Models\SalesReturn::class,
            'SupplierReturns' => \App\Models\SupplierReturn::class,
            'Quotations' => \App\Models\Quotation::class,
            'QuotationItems' => \App\Models\QuotationItem::class,
            'StockCounts' => \App\Models\StockCount::class,
            'ActivityLog' => \App\Models\ActivityLog::class,
            'RestaurantTables' => \App\Models\RestaurantTable::class,
            'TableOrders' => \App\Models\TableOrder::class,
            'TableOrderItems' => \App\Models\TableOrderItem::class,
            'HeldCarts' => \App\Models\HeldCart::class,
            'Promotions' => \App\Models\Promotion::class,
        ];

        $result = [];
        foreach ($sheets as $name => $columns) {
            // Sale::query() applies the "not voided" global scope by
            // default — this export is a full historical record, so voided
            // sales must still be included, same reasoning as Sales/Index.vue.
            $query = $name === 'Sales' ? \App\Models\Sale::withVoided() : $models[$name]::query();
            $rows = $query->get($columns)->map(fn ($row) => $row->only($columns))->toArray();
            $result[] = new ArraySheetExport($name, $columns, $rows);
        }

        Tenancy::clear();

        return $result;
    }
}
