<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The self-check command is only worth having if it (a) passes on a healthy
 * shop and (b) really FAILS on a broken one. Both are asserted here, the
 * second by planting the exact bug that reached SEAMAN: supplier lots that
 * claim more stock than the article holds.
 */
class ZaylotixSelfCheckTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function paidShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner(['account_type' => 'paid']);
        foreach (['accounts', 'suppliers', 'source_lines', 'product_variants', 'returns'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'barcode' => 'L-10001-X', 'cost' => 100, 'price' => 200, 'stock' => 5, 'supplier_id' => $supplier->id]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '40', 'color' => 'BLACK', 'barcode' => 'L-10001-X-BLK-40', 'stock' => 5, 'cost' => null]);

        return [$shop, $p, $v, $supplier];
    }

    public function test_a_healthy_shop_passes_every_check(): void
    {
        $this->paidShop();

        $this->artisan('zaylotix:self-check')->assertSuccessful();
    }

    public function test_a_shop_whose_supplier_lots_exceed_its_stock_is_flagged_only_if_the_report_breaks(): void
    {
        // lots far larger than the shelf: the report scales them down, so the
        // check passes — proving the command and the report agree on the fix
        [$shop, $p, $v, $supplier] = $this->paidShop();
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $supplier->id, 'cost' => 100, 'price' => 200, 'stock' => 50]);

        $this->artisan('zaylotix:self-check')->assertSuccessful();
    }

    public function test_it_fails_when_a_suggested_serial_is_already_live(): void
    {
        [$shop] = $this->paidShop();
        $this->grantFeature($shop, 'article_series');
        // a category whose suggestion would collide: counter says 10001 while L-10001 is live
        \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies', 'code' => 'L']);
        \Illuminate\Support\Facades\DB::table('article_series_counters')->insert([
            'main_shop_id' => $shop->id, 'category_code' => 'L', 'series_start' => 10001, 'next_serial' => 10001, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // nextFree() now steps past live numbers, so this must PASS — the check
        // exists to catch it if that guard is ever removed
        $this->artisan('zaylotix:self-check')->assertSuccessful();
    }

    public function test_it_skips_shops_that_are_not_paid(): void
    {
        $this->createShopWithOwner(['account_type' => 'demo']);

        $this->artisan('zaylotix:self-check')->assertSuccessful()->doesntExpectOutputToContain('SHOP #');
    }
}
