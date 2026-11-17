<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** PDF export alongside the existing Excel/CSV formats — same `$rows` data, a different renderer (see ExportController::downloadPdf). */
class ExportPdfTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_sales_export_can_be_downloaded_as_pdf(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 20, 'total' => 20, 'profit' => 10, 'payment_mode' => 'cash',
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/export/sales?format=pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_stock_export_pdf_works_even_without_a_shop_logo(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->get('/app/export/stock?format=pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_export_still_defaults_to_excel_when_format_is_omitted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');

        $response = $this->actingAs($owner, 'web')->get('/app/export/pl');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }

    public function test_export_all_combines_every_report_into_one_workbook(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 20, 'total' => 20, 'profit' => 10, 'payment_mode' => 'cash',
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/export-all');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }

    public function test_export_all_requires_the_export_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get('/app/export-all')->assertForbidden();
    }

    /** Khaled's explicit ask: owner can customize the PDF's top/bottom margin (e.g. printing on pre-printed letterhead/pad) — this just has to not break rendering at either extreme. */
    public function test_pdf_export_honors_a_custom_margin(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->get('/app/export/stock?format=pdf&margin_top=40&margin_bottom=0');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_stock_movement_register_exports_as_pdf_and_excel(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 20, 'total' => 20, 'profit' => 10, 'payment_mode' => 'cash',
        ]);
        \App\Models\SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => 'Rice', 'qty' => 1, 'price' => 20, 'cost' => 10,
        ]);

        $pdf = $this->actingAs($owner, 'web')->get('/app/export/stock_movement?format=pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $xlsx = $this->actingAs($owner, 'web')->get('/app/export/stock_movement?format=xlsx');
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
    }

    /** The six exports flagged missing in the first full export audit (supplier, purchase report, customer/supplier ledger, payroll, service/warranty report) — each just needs to render without error against a minimal real row. */
    public function test_each_newly_added_export_kind_downloads_successfully(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'export');
        $this->grantFeature($shop, 'suppliers');
        $this->grantFeature($shop, 'hr_payroll');
        $this->grantFeature($shop, 'service_billing');

        $supplier = \App\Models\Supplier::create(['shop_id' => $shop->id, 'name' => 'ABC Traders', 'phone' => '01700000000', 'payable' => 500]);
        \App\Models\Purchase::create(['shop_id' => $shop->id, 'supplier' => $supplier->name, 'supplier_id' => $supplier->id, 'memo' => 'PINV-1', 'amount' => 500, 'method' => 'credit', 'date' => now()->toDateString()]);
        \App\Models\SupplierPayment::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'amount' => 100, 'payable_before' => 500, 'payable_after' => 400, 'method' => 'cash', 'date' => now()->toDateString()]);

        $customer = \App\Models\Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 50]);
        \App\Models\Payment::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'amount' => 20, 'due_before' => 70, 'due_after' => 50, 'method' => 'cash', 'date' => now()->toDateString()]);

        $employee = \App\Models\Employee::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'designation' => 'Sales', 'salary_type' => 'monthly', 'basic_salary' => 15000, 'joining_date' => now()->subMonths(2)->toDateString()]);
        \App\Models\SalaryPayment::create(['shop_id' => $shop->id, 'employee_id' => $employee->id, 'month' => now()->format('Y-m'), 'basic_salary' => 15000, 'net_paid' => 15000, 'method' => 'cash', 'paid_date' => now()->toDateString()]);

        \App\Models\ServiceTicket::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'device_label' => 'Samsung A16', 'status' => 'received', 'is_warranty_claim' => true, 'received_at' => now()]);

        foreach (['supplier', 'purchase', 'customer_ledger', 'supplier_ledger', 'payroll', 'service_report'] as $kind) {
            $response = $this->actingAs($owner, 'web')->get("/app/export/{$kind}?format=xlsx");
            $response->assertOk();
            $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'), "kind={$kind}");
        }
    }
}
