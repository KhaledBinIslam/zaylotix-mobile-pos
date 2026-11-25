<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Accounts -> stock value -> "by supplier" (AccountsController::
 * stockValueBySupplier): lots first, the article's own supplier for what
 * lots don't cover, "unknown" for the rest — and under a shared pool the
 * same business-wide figure on every branch.
 */
class SupplierStockValueTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function rowsOf($response): array
    {
        $rows = [];
        $response->assertOk()->assertInertia(function ($page) use (&$rows) {
            $page->component('App/Accounts/StockValueBySupplier');
            $rows = $page->toArray()['props'];
        });

        return $rows;
    }

    private function byName(array $props): array
    {
        $out = [];
        foreach ($props['rows'] as $r) {
            $out[$r['unknown'] ? 'unknown' : $r['name']] = $r;
        }

        return $out;
    }

    public function test_a_lot_tracked_shop_is_split_by_lot_then_by_article_supplier_then_unknown(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['accounts', 'suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $a = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $b = Supplier::create(['shop_id' => $shop->id, 'name' => 'Sujon', 'payable' => 0]);

        // article from Bulu: 10 pcs @ 100 = 1000; lots cover 4 @ 90 (Bulu) + 3 @ 110 (Sujon) = 690; rest 310 -> Bulu
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 100, 'price' => 200, 'stock' => 10, 'supplier_id' => $a->id]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '36', 'stock' => 10, 'cost' => null]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $a->id, 'cost' => 90, 'price' => 200, 'stock' => 4]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $b->id, 'cost' => 110, 'price' => 200, 'stock' => 3]);
        // plain product, no supplier anywhere: 2 @ 50 = 100 -> unknown
        Product::create(['shop_id' => $shop->id, 'name' => 'Polish', 'cost' => 50, 'price' => 80, 'stock' => 2]);

        $props = $this->rowsOf($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier')));
        $rows = $this->byName($props);

        $this->assertEquals(1100, $props['total']);
        $this->assertEquals(670, $rows['Bulu']['value']);   // 360 lot + 310 article remainder
        $this->assertEquals(330, $rows['Sujon']['value']);  // 330 lot
        $this->assertEquals(100, $rows['unknown']['value']);
        $this->assertEquals(1100, array_sum(array_column($props['rows'], 'value')), 'the split always adds up to the stock value card');
        $this->assertSame(['lot', 'article'], array_column($rows['Bulu']['products'], 'via'));
        $this->assertTrue($props['usesLots']);
        $this->assertSame([], $props['pooledWith']);
    }

    /**
     * SEAMAN live, 2026-11-25: three articles' lot ledgers ran ahead of their
     * stock (Bideshi Boiler Suit: 22 pcs on the shelf, 46 in lots), and the
     * report's rows added up to ৳25,000 MORE than the stock value card.
     */
    public function test_lots_larger_than_the_articles_stock_never_push_the_total_above_the_card(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['accounts', 'suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $a = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $b = Supplier::create(['shop_id' => $shop->id, 'name' => 'Sujon', 'payable' => 0]);

        // 10 pcs @ 100 = 1000 on the shelf, but the lots claim 30 @ 100 = 3000
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Suit', 'cost' => 100, 'price' => 200, 'stock' => 10, 'supplier_id' => $a->id]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'stock' => 10, 'cost' => null]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $a->id, 'cost' => 100, 'price' => 200, 'stock' => 20]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $b->id, 'cost' => 100, 'price' => 200, 'stock' => 10]);

        $props = $this->rowsOf($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier')));
        $rows = $this->byName($props);

        $this->assertEquals(1000, $props['total']);
        $this->assertEquals(1000, array_sum(array_column($props['rows'], 'value')), 'rows still add up to the card');
        $this->assertEqualsWithDelta(666.67, $rows['Bulu']['value'], 0.01, 'pro rata 20 of 30 lots');
        $this->assertEqualsWithDelta(333.33, $rows['Sujon']['value'], 0.01);
        $this->assertEqualsWithDelta(10, $rows['Bulu']['qty'] + $rows['Sujon']['qty'], 0.01, 'quantities do not exceed the shelf either');
    }

    public function test_a_pooled_business_shows_the_same_business_wide_split_on_both_branches(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner();
        [$branch, $branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        foreach ([$main, $branch] as $s) {
            foreach (['accounts', 'suppliers', 'source_lines', 'product_variants'] as $f) {
                $this->grantFeature($s, $f);
            }
        }
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');

        $a = Supplier::create(['shop_id' => $main->id, 'name' => 'Bulu', 'payable' => 0]);
        $b = Supplier::create(['shop_id' => $branch->id, 'name' => 'Agrabad Traders', 'payable' => 0]);

        // born at the main shop -> the creation hooks mirror it (and its size, same stock) into the branch
        $master = Product::create(['shop_id' => $main->id, 'name' => 'L-10001', 'cost' => 100, 'price' => 200, 'stock' => 0, 'supplier_id' => $a->id]);
        $mv = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $master->id, 'size' => '36', 'stock' => 10, 'cost' => null]);
        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $master->id)->firstOrFail();
        $cv = ProductVariant::withoutGlobalScopes()->where('product_id', $copy->id)->firstOrFail();
        $this->assertNull($copy->supplier_id, 'a branch copy is never stamped with a supplier — the master\'s is used');

        // lots live per shop: 4 @ 90 received at main (Bulu), 2 @ 120 received at the branch (Agrabad Traders)
        ProductSource::create(['shop_id' => $main->id, 'product_id' => $master->id, 'product_variant_id' => $mv->id, 'supplier_id' => $a->id, 'cost' => 90, 'price' => 200, 'stock' => 4]);
        ProductSource::create(['shop_id' => $branch->id, 'product_id' => $copy->id, 'product_variant_id' => $cv->id, 'supplier_id' => $b->id, 'cost' => 120, 'price' => 200, 'stock' => 2]);

        $expect = function (array $props, string $who) {
            $rows = $this->byName($props);
            $this->assertEquals(1000, $props['total'], "{$who}: pooled stock value 10 x 100");
            $this->assertEquals(760, $rows['Bulu']['value'], "{$who}: 360 lot + 400 remainder via the master's supplier");
            $this->assertEquals(240, $rows['Agrabad Traders']['value'], "{$who}: the branch's own lot counts business-wide");
            $this->assertArrayNotHasKey('unknown', $rows, "{$who}: nothing left unattributed");
            $this->assertEquals(1000, array_sum(array_column($props['rows'], 'value')));
            $this->assertCount(1, $props['pooledWith']);
        };

        Tenancy::set($main->id);
        $expect($this->rowsOf($this->actingAs($mainOwner, 'web')->get(route('app.accounts.stockValueBySupplier'))), 'main');
        Tenancy::set($branch->id);
        $expect($this->rowsOf($this->actingAs($branchOwner, 'web')->get(route('app.accounts.stockValueBySupplier'))), 'branch');
    }

    public function test_a_shop_without_lots_is_split_by_each_articles_supplier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['accounts', 'suppliers', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);
        $a = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 0]);
        $p = Product::create(['shop_id' => $shop->id, 'name' => '80001', 'cost' => 350, 'price' => 500, 'stock' => 3, 'supplier_id' => $a->id]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '40', 'stock' => 3, 'cost' => null]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Nameless', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $props = $this->rowsOf($this->actingAs($owner, 'web')->get(route('app.accounts.stockValueBySupplier')));
        $rows = $this->byName($props);

        $this->assertFalse($props['usesLots']);
        $this->assertEquals(1050, $rows['Bulu']['value']);
        $this->assertEquals(50, $rows['unknown']['value']);
        $this->assertEquals(1100, $props['total']);
    }

    public function test_gating_matches_the_accounts_routes(): void
    {
        [$noSupplier, $owner1] = $this->createShopWithOwner();
        $this->grantFeature($noSupplier, 'accounts'); // accounts, but neither suppliers nor source_lines
        $this->actingAs($owner1, 'web')->get(route('app.accounts.stockValueBySupplier'))->assertNotFound();

        [$noAccounts, $owner2] = $this->createShopWithOwner();
        $this->grantFeature($noAccounts, 'suppliers');
        $this->actingAs($owner2, 'web')->get(route('app.accounts.stockValueBySupplier'))->assertForbidden();
    }
}
