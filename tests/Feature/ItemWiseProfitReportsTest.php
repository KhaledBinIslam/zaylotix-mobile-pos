<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The four new profit cuts added on top of the existing per-product report:
 * category profit/margin, per-variant, per-IMEI, and per-salesperson — plus
 * the main-shop-only per-branch-per-product cut.
 */
class ItemWiseProfitReportsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_category_report_now_includes_profit_and_margin(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cat = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Grocery', 'name_en' => 'Grocery', 'emoji' => '🛒']);
        $product = Product::create(['shop_id' => $shop->id, 'category_id' => $cat->id, 'name' => 'Rice', 'cost' => 40, 'price' => 50, 'stock' => 100]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
        ])->assertOk(); // revenue 500, profit 100 -> margin 20%

        $today = now()->toDateString();
        $report = Reports::categoryReport($today, $today);

        $this->assertSame(100.0, $report['Grocery']['profit']);
        $this->assertSame(20.0, $report['Grocery']['margin_pct']);
    }

    public function test_variant_profit_report_keyed_by_variant_not_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 0, 'price' => 0, 'stock' => 0]);
        $v42 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'color' => 'Black', 'price' => 1000, 'cost' => 600, 'stock' => 10]);
        $v43 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '43', 'color' => 'Black', 'price' => 1100, 'cost' => 650, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $product->id, 'product_variant_id' => $v42->id, 'qty' => 1],
                ['product_id' => $product->id, 'product_variant_id' => $v43->id, 'qty' => 1],
            ],
            'payments' => [['method' => 'cash', 'amount' => 2100]],
        ])->assertOk();

        $today = now()->toDateString();
        $report = Reports::variantProfitReport($today, $today);

        $this->assertCount(2, $report);
        $byVariant = collect($report)->keyBy('product_variant_id');
        $this->assertSame(400.0, (float) $byVariant[$v42->id]->profit); // 1000-600
        $this->assertSame(450.0, (float) $byVariant[$v43->id]->profit); // 1100-650
    }

    public function test_serial_profit_report_shows_one_row_per_sold_imei(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'serial_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 15000, 'price' => 18000, 'stock' => 2]);
        ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '111222333', 'status' => 'in_stock']);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'imei' => '111222333']],
            'payments' => [['method' => 'cash', 'amount' => 18000]],
        ])->assertOk();

        $today = now()->toDateString();
        $report = Reports::serialProfitReport($today, $today);

        $this->assertCount(1, $report);
        $this->assertSame('111222333', $report[0]['imei']);
        $this->assertSame(3000.0, $report[0]['profit']); // 18000-15000
    }

    public function test_salesperson_profit_report_groups_by_who_rang_it_up(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'pos');
        $cashier = User::create(['shop_id' => $shop->id, 'name' => 'Cashier Asif', 'phone' => '01711119999', 'password' => 'password', 'role' => 'staff', 'permissions' => ['pos']]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 80, 'price' => 100, 'stock' => 50]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
        ])->assertOk(); // profit 100

        $this->actingAs($cashier, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ])->assertOk(); // profit 40

        $today = now()->toDateString();
        $report = collect(Reports::salespersonProfitReport($today, $today))->keyBy('name');

        $this->assertSame(100.0, $report['Owner']->profit ?? $report['Owner']['profit']);
        $this->assertSame(40.0, $report['Cashier Asif']['profit']);
    }

    public function test_branch_product_profit_report_splits_by_shop(): void
    {
        $type = BusinessType::firstOrCreate(['slug' => 'general'], ['label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);
        [$main, $mainOwner] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $branch = Shop::create(array_merge(
            $main->only(['sales_mode', 'lang', 'plan', 'status', 'subscription_start', 'subscription_expiry', 'business_type_id']),
            ['parent_shop_id' => $main->id, 'name' => 'Branch A', 'phone' => '019'.random_int(10000000, 99999999), 'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0, 'invoice_counter' => 1000, 'onboarded_at' => now()]
        ));
        $branchOwner = User::create(['shop_id' => $branch->id, 'name' => 'Branch Owner', 'phone' => $branch->phone, 'password' => 'password', 'role' => 'owner']);

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Pen', 'cost' => 5, 'price' => 10, 'stock' => 100]);
        $branchProduct = Product::create(['shop_id' => $branch->id, 'name' => 'Pen', 'cost' => 5, 'price' => 10, 'stock' => 100]);

        $this->actingAs($mainOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $mainProduct->id, 'qty' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 40]],
        ])->assertOk(); // profit 20

        $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $branchProduct->id, 'qty' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk(); // profit 50

        $today = now()->toDateString();
        $report = collect(Reports::branchProductProfitReport([$main->id, $branch->id], $today, $today))->keyBy('shop_name');

        $this->assertSame(20.0, $report[$main->name]['profit']);
        $this->assertSame(50.0, $report['Branch A']['profit']);
    }

    public function test_all_four_new_reports_are_reachable_from_the_reports_page(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'reports');
        $this->grantFeature($shop, 'product_variants');
        $this->grantFeature($shop, 'serial_tracking');

        $response = $this->actingAs($owner, 'web')->get('/app/reports');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('salespersonProfitReport')
            ->has('variantProfitReport')
            ->has('serialProfitReport')
        );
    }
}
