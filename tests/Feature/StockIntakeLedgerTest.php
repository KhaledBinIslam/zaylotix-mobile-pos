<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "koto tk er mal utche utteche ey ammount jate vul na dekhai
 * dorkar hole kon serail e koto tk er maal utche tai etu tk eta jate
 * details e dekhai, details e report paowa jai." Every path stock walks
 * in through writes the ledger in the same transaction, and the report
 * reads only the ledger.
 */
class StockIntakeLedgerTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creating_an_article_records_each_size_at_its_own_cost(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');

        $this->actingAs($owner, 'web')->post('/app/articles', [
            'client_uuid' => (string) Str::uuid(),
            'new_category_name' => 'Ladies',
            'category_code' => 'L',
            'manufacturer_code' => 'SMS',
            'cost' => 1300,
            'price' => 1990,
            'colorsText' => 'SILVER',
            'sizesText' => '36-37',
            'variants' => [
                ['color' => 'SILVER', 'size' => '36', 'qty' => 2],
                ['color' => 'SILVER', 'size' => '37', 'qty' => 3],
            ],
        ])->assertSessionHasNoErrors();

        $rows = StockIntake::where('shop_id', $shop->id)->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(5 * 1300, $rows->sum('total_cost'));
        $this->assertSame(['article_create'], $rows->pluck('source')->unique()->all());
    }

    public function test_variant_stock_in_records_the_added_quantity_at_the_given_cost(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 500, 'price' => 900, 'stock' => 2]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '36', 'stock' => 2, 'cost' => 500]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.stockIn', $variant->id), [
            'qty' => 4, 'cost' => 550,
        ])->assertSessionHasNoErrors();

        $row = StockIntake::where('shop_id', $shop->id)->sole();
        $this->assertEquals(4, $row->qty);
        $this->assertEquals(550, $row->unit_cost);
        $this->assertEquals(2200, $row->total_cost);
        $this->assertSame('variant_stock_in', $row->source);
        $this->assertSame($variant->id, $row->product_variant_id);
    }

    public function test_plain_product_stock_in_and_creation_both_hit_the_ledger(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Belt', 'cost' => 100, 'price' => 250, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post(route('app.products.stockIn', $product->id), [
            'qty' => 10, 'cost' => 110,
        ])->assertSessionHasNoErrors();

        $row = StockIntake::where('shop_id', $shop->id)->sole();
        $this->assertEquals(1100, $row->total_cost);
        $this->assertSame('product_stock_in', $row->source);
    }

    public function test_the_report_groups_by_article_with_entries_and_an_exact_total(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-90000', 'cost' => 700, 'price' => 1200, 'stock' => 0]);
        StockIntake::create([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'user_id' => $owner->id,
            'qty' => 6, 'unit_cost' => 700, 'total_cost' => 4200, 'source' => 'article_create',
        ]);
        StockIntake::create([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'user_id' => $owner->id,
            'qty' => 2, 'unit_cost' => 750, 'total_cost' => 1500, 'source' => 'variant_stock_in',
        ]);

        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);
        $report = \App\Support\Reports::intakeReport(now()->toDateString(), now()->toDateString());

        $this->assertEquals(5700, $report['total_cost']);
        $this->assertEquals(8, $report['total_qty']);
        $this->assertCount(1, $report['products']);
        $this->assertSame('L-90000', $report['products'][0]['name']);
        $this->assertCount(2, $report['products'][0]['entries']);
    }

    public function test_a_branch_intake_shows_in_the_main_shops_report_as_one_business(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner();
        $branch = \App\Models\Shop::create([
            'name' => 'Liberty', 'phone' => 'PENDING-'.uniqid(), 'parent_shop_id' => $main->id,
            'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
            'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'vat_mode' => 'none', 'invoice_counter' => 1000,
            'onboarded_at' => now(),
        ]);
        // the SAME article exists as a different row in each shop
        // (CatalogSync copies) — the report must show ONE line for it
        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'L-90000', 'cost' => 700, 'price' => 1200, 'stock' => 0]);
        $branchProduct = Product::create(['shop_id' => $branch->id, 'name' => 'L-90000', 'cost' => 700, 'price' => 1200, 'stock' => 0]);
        StockIntake::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'qty' => 2, 'unit_cost' => 700, 'total_cost' => 1400, 'source' => 'article_create']);
        StockIntake::create(['shop_id' => $branch->id, 'product_id' => $branchProduct->id, 'qty' => 3, 'unit_cost' => 700, 'total_cost' => 2100, 'source' => 'variant_stock_in']);

        $this->actingAs($mainOwner, 'web');
        \App\Support\Tenancy::set($main->id);
        $report = \App\Support\Reports::intakeReport(now()->toDateString(), now()->toDateString());

        $this->assertEquals(3500, $report['total_cost']);
        $this->assertCount(1, $report['products']);
        $this->assertSame('L-90000', $report['products'][0]['name']);
        $this->assertCount(2, $report['products'][0]['entries']);
    }

    public function test_another_shops_intakes_never_leak_into_the_report(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Other', 'cost' => 50, 'price' => 90, 'stock' => 0]);
        StockIntake::create([
            'shop_id' => $shopB->id, 'product_id' => $productB->id,
            'qty' => 3, 'unit_cost' => 50, 'total_cost' => 150, 'source' => 'product_stock_in',
        ]);

        $this->actingAs($ownerA, 'web');
        \App\Support\Tenancy::set($shopA->id);
        $report = \App\Support\Reports::intakeReport(now()->toDateString(), now()->toDateString());

        $this->assertEquals(0, $report['total_cost']);
        $this->assertSame([], $report['products']);
    }
}
