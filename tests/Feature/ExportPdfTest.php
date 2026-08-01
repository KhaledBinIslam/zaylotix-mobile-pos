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
}
