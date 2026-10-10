<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Every `date`-cast model used to serialize as a full UTC timestamp
 * ("2026-10-09T18:00:00.000000Z") — since the frontend just interpolates
 * `{{ sale.date }}` raw with no further parsing, that full timestamp (really
 * midnight Asia/Dhaka, converted to true UTC) rendered as-is, visibly a day
 * behind what was actually sold. Every such model now casts as `date:Y-m-d`
 * so this can never come back as anything but a plain calendar date.
 */
class DateSerializationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_sales_date_serializes_as_a_plain_calendar_date_not_a_full_timestamp(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'sales_history');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => '2026-10-10', 'time' => '21:41:02',
            'subtotal' => 20, 'total' => 20, 'profit' => 10, 'payment_mode' => 'cash',
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/sales/'.$sale->id);

        $response->assertOk()->assertInertia(fn ($page) => $page->where('sale.date', '2026-10-10'));
    }

    public function test_reports_bill_list_shows_plain_calendar_dates(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'reports');
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-1', 'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 20, 'total' => 20, 'profit' => 10, 'payment_mode' => 'cash',
        ]);

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=today');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('sales.0.date', now()->toDateString()));
    }
}
