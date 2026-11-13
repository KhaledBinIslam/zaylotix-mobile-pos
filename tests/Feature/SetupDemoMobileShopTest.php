<?php

namespace Tests\Feature;

use App\Console\Commands\SetupDemoMobileShop;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupDemoMobileShopTest extends TestCase
{
    use RefreshDatabase;

    private function runSetup(): Shop
    {
        $this->artisan('shop:setup-demo-mobile', ['--phone' => '01799000099', '--password' => 'demo1234'])
            ->assertSuccessful();

        return Shop::withoutGlobalScopes()->where('phone', '01799000099')->firstOrFail();
    }

    public function test_builds_a_connected_mobile_shop_demo(): void
    {
        $shop = $this->runSetup();

        $this->assertSame('demo', $shop->account_type);
        $this->assertTrue($shop->hasFeature('serial_tracking'));

        // Samsung A06: bought 5, sold 2 -> 3 in stock, stock mirror agrees
        $a06 = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'Samsung Galaxy A06 4/64')->firstOrFail();
        $this->assertEquals(3, (float) $a06->stock);
        $this->assertSame(3, ProductSerial::withoutGlobalScopes()->where('product_id', $a06->id)->where('status', 'in_stock')->count());
        $this->assertSame(2, ProductSerial::withoutGlobalScopes()->where('product_id', $a06->id)->where('status', 'sold')->count());

        // every IMEI is 15 digits and Luhn-valid
        $imei = ProductSerial::withoutGlobalScopes()->where('product_id', $a06->id)->first()->imei;
        $this->assertMatchesRegularExpression('/^\d{15}$/', $imei);
        $this->assertSame(substr(SetupDemoMobileShop::imei(substr($imei, 0, 14), 0), 14), substr($imei, 14));

        // a sold phone's sale item is linked to that exact serial
        $soldSerial = ProductSerial::withoutGlobalScopes()->where('product_id', $a06->id)->where('status', 'sold')->first();
        $item = SaleItem::withoutGlobalScopes()->where('product_serial_id', $soldSerial->id)->first();
        $this->assertNotNull($item, 'sold serial must be linked to a sale item');

        // a customer's due equals the sum of their own credit invoices
        $creditSale = Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->where('payment_mode', 'credit')->whereNotNull('customer_id')->first();
        $this->assertNotNull($creditSale);
        $expectedDue = Sale::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('customer_id', $creditSale->customer_id)->where('payment_mode', 'credit')->sum('total');
        $customer = \App\Models\Customer::withoutGlobalScopes()->findOrFail($creditSale->customer_id);
        $this->assertEquals((float) $expectedDue, (float) $customer->due);

        // credit purchases land on the supplier's payable
        $this->assertTrue(Supplier::withoutGlobalScopes()->where('shop_id', $shop->id)->where('payable', '>', 0)->exists());

        // volume sanity, per the brief
        $this->assertGreaterThanOrEqual(50, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertGreaterThanOrEqual(30, Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_rerun_duplicates_nothing(): void
    {
        $shop = $this->runSetup();
        $counts = fn () => [
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
            ProductSerial::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
            Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
            SaleItem::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
        ];
        $before = $counts();

        $this->runSetup();

        $this->assertSame($before, $counts());
    }

    public function test_rollback_removes_everything(): void
    {
        $shop = $this->runSetup();

        $this->artisan('shop:setup-demo-mobile', ['--phone' => '01799000099', '--rollback' => true])->assertSuccessful();

        $this->assertNull(Shop::withoutGlobalScopes()->find($shop->id));
        $this->assertSame(0, ProductSerial::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, Sale::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }
}
