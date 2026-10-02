<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopDataExportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_admin_can_download_a_single_shops_data_export(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'export-admin@test.com', 'password' => 'password']);
        [$shop] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Exported Product', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $response = $this->actingAs($admin, 'admin')->get("/admin/shops/{$shop->id}/export");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_shop_user_cannot_access_the_export_route(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get("/admin/shops/{$shop->id}/export");

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_download_a_portable_sql_dump_of_one_shop(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'sql-export-admin@test.com', 'password' => 'password']);
        [$shop] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Dumped Product', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $response = $this->actingAs($admin, 'admin')->get("/admin/shops/{$shop->id}/export-sql");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/sql');
        $content = $response->getContent();
        $this->assertStringContainsString('INSERT INTO `products`', $content);
        $this->assertStringContainsString('Dumped Product', $content);
        $this->assertStringContainsString("INSERT INTO `shops`", $content);
    }

    public function test_sql_dump_only_contains_the_requested_shops_rows(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'sql-scope-admin@test.com', 'password' => 'password']);
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopA->id, 'name' => 'Shop A Product', 'cost' => 1, 'price' => 2, 'stock' => 5]);
        Product::create(['shop_id' => $shopB->id, 'name' => 'Shop B Product', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $dump = \App\Support\ShopSqlDump::generate($shopA->fresh());

        $this->assertStringContainsString('Shop A Product', $dump);
        $this->assertStringNotContainsString('Shop B Product', $dump);
    }

    public function test_shop_user_cannot_access_the_sql_export_route(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get("/admin/shops/{$shop->id}/export-sql");

        $response->assertRedirect(route('admin.login'));
    }

    /**
     * Regression test: ShopSqlDump::TABLES is a hand-maintained list, not
     * auto-discovered from the schema — every table added since Phase 1/2
     * (suppliers, sale_payments, product_batches/variants/serials,
     * activity_logs, restaurant_tables, table_orders, table_order_items)
     * was silently missing from every shop's export until this was added.
     */
    public function test_sql_dump_includes_every_table_added_this_session(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = \App\Models\Product::create(['shop_id' => $shop->id, 'name' => 'P', 'cost' => 1, 'price' => 2, 'stock' => 5]);
        \App\Models\Supplier::create(['shop_id' => $shop->id, 'name' => 'Dumped Supplier', 'payable' => 100]);
        \App\Models\ActivityLog::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'action' => 'test.action', 'description' => 'Dumped activity line', 'created_at' => now()]);
        \App\Models\ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'DumpedBatch', 'qty' => 5]);
        \App\Models\ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'DumpedVariantSize', 'stock' => 3]);
        \App\Models\ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => 'DumpedSerialImei']);
        $table = \App\Models\RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'DumpedTableName', 'status' => 'free']);
        $order = \App\Models\TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);
        \App\Models\TableOrderItem::create(['shop_id' => $shop->id, 'table_order_id' => $order->id, 'product_name' => 'DumpedOrderItem', 'qty' => 1, 'price' => 2, 'cost' => 1]);

        // Production-readiness audit: the exact same hand-maintained-list
        // drift this test already regression-guards against once (see the
        // docblock above) happened again — ~20 more tables added since were
        // silently missing. Same pattern: create one row per table, assert
        // its data actually made it into the dump.
        $loan = \App\Models\Loan::create(['shop_id' => $shop->id, 'party_name' => 'DumpedLoanParty', 'type' => 'given', 'principal' => 500, 'outstanding' => 500, 'method' => 'cash', 'date' => now()->toDateString()]);
        \App\Models\LoanPayment::create(['shop_id' => $shop->id, 'loan_id' => $loan->id, 'amount' => 50, 'method' => 'cash', 'date' => now()->toDateString()]);
        $partner = \App\Models\Partner::create(['shop_id' => $shop->id, 'name' => 'DumpedPartnerName', 'ownership_percent' => 50, 'invested_amount' => 1000, 'joined_date' => now()->toDateString()]);
        \App\Models\PartnerTransaction::create(['shop_id' => $shop->id, 'partner_id' => $partner->id, 'type' => 'investment', 'amount' => 100, 'method' => 'cash', 'date' => now()->toDateString()]);
        \App\Models\CashTransaction::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'type' => 'deposit', 'amount' => 20, 'note' => 'DumpedCashNote', 'date' => now()->toDateString()]);
        $employee = \App\Models\Employee::create(['shop_id' => $shop->id, 'name' => 'DumpedEmployeeName', 'salary_type' => 'monthly', 'basic_salary' => 5000, 'joining_date' => now()->toDateString(), 'status' => 'active']);
        \App\Models\Attendance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'date' => now()->toDateString(), 'status' => 'present']);
        \App\Models\SalaryAdvance::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'amount' => 100, 'outstanding' => 100, 'method' => 'cash', 'date' => now()->toDateString()]);
        \App\Models\SalaryPayment::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'month' => now()->format('Y-m'), 'basic_salary' => 5000, 'net_paid' => 5000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);
        \App\Models\Reservation::create(['shop_id' => $shop->id, 'name' => 'DumpedReservationName', 'reservation_at' => now()->addDay(), 'guest_count' => 2, 'status' => 'reserved']);
        \App\Models\WorkPeriod::create(['shop_id' => $shop->id, 'opened_by' => $owner->id, 'opened_at' => now(), 'opening_cash' => 500, 'cash_balance_at_open' => 500]);
        $ingredient = \App\Models\Ingredient::create(['shop_id' => $shop->id, 'name' => 'DumpedIngredientName', 'unit' => 'kg', 'stock' => 10, 'cost' => 50]);
        \App\Models\ProductRecipe::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'ingredient_id' => $ingredient->id, 'qty_per_unit' => 0.1]);
        $preparation = \App\Models\Preparation::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'DumpedPreparationName', 'qty' => 5]);
        \App\Models\PreparationItem::create(['shop_id' => $shop->id, 'preparation_id' => $preparation->id, 'ingredient_id' => $ingredient->id, 'ingredient_name' => 'DumpedPrepIngredient', 'qty_consumed' => 1]);
        $sale = \App\Models\Sale::create(['shop_id' => $shop->id, 'invoice_no' => 'DUMPED-INV-1', 'date' => now()->toDateString(), 'time' => '10:00:00', 'subtotal' => 100, 'total' => 100, 'profit' => 10, 'payment_mode' => 'cash']);
        \App\Models\SaleRating::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'stars' => 5, 'comment' => 'DumpedRatingComment']);
        \App\Models\GatewayPayment::create(['shop_id' => $shop->id, 'provider' => 'bkash', 'reference' => 'DumpedGatewayRef', 'amount' => 100, 'status' => 'completed', 'checkout_payload' => []]);
        \App\Models\WhatsappBulkLog::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'send_type' => 'text', 'message' => 'DumpedWaBulkMessage', 'recipients_count' => 1]);
        \App\Models\WhatsappMessageTemplate::create(['shop_id' => $shop->id, 'label' => 'DumpedWaTemplateLabel', 'send_type' => 'text', 'message' => 'hi']);

        $dump = \App\Support\ShopSqlDump::generate($shop->fresh());

        foreach ([
            'Dumped Supplier', 'Dumped activity line', 'DumpedBatch', 'DumpedVariantSize',
            'DumpedSerialImei', 'DumpedTableName', 'DumpedOrderItem',
            'DumpedLoanParty', 'DumpedPartnerName', 'DumpedCashNote', 'DumpedEmployeeName',
            'DumpedReservationName', 'DumpedIngredientName', 'DumpedPreparationName',
            'DumpedPrepIngredient', 'DumpedRatingComment', 'DumpedGatewayRef',
            'DumpedWaBulkMessage', 'DumpedWaTemplateLabel',
        ] as $needle) {
            $this->assertStringContainsString($needle, $dump, "SQL dump is missing data from: {$needle}");
        }
    }

    public function test_excel_export_includes_a_sheet_for_every_table_added_this_session(): void
    {
        [$shop] = $this->createShopWithOwner();

        $sheets = (new \App\Exports\ShopDataExport($shop))->sheets();
        $titles = array_map(fn ($s) => $s->title(), $sheets);

        foreach ([
            'Suppliers', 'ActivityLog', 'ProductBatches', 'ProductVariants',
            'ProductSerials', 'SalePayments', 'RestaurantTables', 'TableOrders', 'TableOrderItems',
            'Loans', 'LoanPayments', 'Partners', 'PartnerTransactions', 'CashTransactions',
            'Employees', 'Attendances', 'SalaryAdvances', 'SalaryPayments', 'Reservations',
            'WorkPeriods', 'Ingredients', 'ProductRecipes', 'Preparations', 'PreparationItems',
            'SaleRatings', 'StockTransfers', 'GatewayPayments', 'WhatsappBulkLogs', 'WhatsappMessageTemplates',
        ] as $expected) {
            $this->assertContains($expected, $titles, "Excel export is missing a sheet for: {$expected}");
        }
    }
}
