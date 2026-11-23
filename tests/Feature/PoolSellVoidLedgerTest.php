<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Audit, 2026-11-23: every money/stock effect of SELL and VOID on a
 * shared_stock_pool pair (Baby Park shop 80 + Liberty-Agrabad shop 81 in
 * production). Traced PosController::checkout()/performCheckout() and
 * App\Support\SaleReversal — this file proves, rather than assumes, that
 * the trace is right. No fix in this file; every test here passes against
 * the CURRENT code, documenting correct behavior already in place.
 */
class PoolSellVoidLedgerTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function stockValue(Shop $shop): float
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\App\AccountsController::class, 'stockValue');
        $m->setAccessible(true);
        \App\Support\Tenancy::set($shop->id);

        return $m->invoke(new \App\Http\Controllers\App\AccountsController());
    }

    /** @return array{0: Shop, 1: Shop} main (pooled) + branch */
    private function pooledPair(): array
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return [$main, $branch];
    }

    public function test_a_pooled_cash_sale_drops_stock_everywhere_and_credits_only_the_selling_shops_cash(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Shoe', 'cost' => 500, 'price' => 1000, 'stock' => 10]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => '40', 'color' => 'Black', 'stock' => 10]);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->firstOrFail();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('product_id', $branchProduct->id)->firstOrFail();

        $branchCashBefore = (float) $branch->fresh()->cash_balance;
        $mainCashBefore = (float) $main->fresh()->cash_balance;

        $resp = $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $branchProduct->id, 'product_variant_id' => $branchVariant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 3000]],
        ]);
        $resp->assertOk();

        // stock dropped to 7, identically, in BOTH branches — both the
        // product and variant pool number
        foreach ([$mainProduct, $branchProduct] as $p) {
            $this->assertEquals(7, (float) $p->fresh()->stock);
        }
        foreach ([$mainVariant, $branchVariant] as $v) {
            $this->assertEquals(7, (float) $v->fresh()->stock);
        }
        $this->assertEquals($this->stockValue($main), $this->stockValue($branch), 'pooled stock value must be identical in both shops');
        $this->assertEquals(7 * 500, $this->stockValue($branch));

        // income/cash counted ONLY on the selling shop (branch) -- the
        // main shop's own cash_balance is untouched by a branch sale
        $this->assertEquals($branchCashBefore + 3000, (float) $branch->fresh()->cash_balance);
        $this->assertEquals($mainCashBefore, (float) $main->fresh()->cash_balance, 'a branch sale must never touch the MAIN shops cash');

        $sale = Sale::where('shop_id', $branch->id)->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertSame($branch->id, $sale->shop_id, 'the sale belongs to the selling shop only');
        $this->assertEquals(0, Sale::where('shop_id', $main->id)->count(), 'the sale must never also appear under the main shop');
        $this->assertEquals((1000 - 500) * 3, (float) $sale->profit, 'profit = (price - cost) * qty');
    }

    public function test_a_variant_with_null_cost_falls_back_to_the_products_cost_for_profit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Belt', 'cost' => 100, 'price' => 250, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'cost' => null, 'stock' => 5]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 250]],
        ])->assertOk();

        $sale = Sale::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertEquals(250 - 100, (float) $sale->profit, 'null variant cost must fall back to the products own cost, not zero/null');
    }

    public function test_sending_the_same_checkout_payload_twice_is_idempotent_via_client_uuid(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashBefore = (float) $shop->fresh()->cash_balance;
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        $payload = [
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 200]],
        ];

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload)->assertOk();
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload)->assertOk();

        $this->assertEquals(8, (float) $product->fresh()->stock, 'a resubmitted client_uuid must never decrement stock twice');
        $this->assertSame(1, Sale::where('shop_id', $shop->id)->count(), 'a resubmitted client_uuid must never create a second sale');
        $this->assertEquals($cashBefore + 200, (float) $shop->fresh()->cash_balance, 'cash must not be credited twice for the same client_uuid');
    }

    public function test_negative_stock_is_allowed_only_with_an_explicit_oversell_override(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Pen', 'cost' => 5, 'price' => 10, 'stock' => 1]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertStatus(422);

        $owner->update(['permissions' => array_values(array_unique(array_merge($owner->permissions ?? [], ['oversell'])))]);
        $this->actingAs($owner->fresh(), 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5, 'allow_oversell' => true]],
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertOk();

        $this->assertEquals(-4, (float) $product->fresh()->stock);
    }

    public function test_voiding_a_pooled_sale_restores_stock_everywhere_and_reverses_cash_exactly_once(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();
        $cashBefore = (float) $branch->fresh()->cash_balance;

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Shoe', 'cost' => 500, 'price' => 1000, 'stock' => 10]);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->firstOrFail();

        $this->actingAs($branchOwner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $branchProduct->id, 'qty' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 4000]],
        ])->assertOk();
        $this->assertEquals(6, (float) $branchProduct->fresh()->stock);
        $this->assertEquals($cashBefore + 4000, (float) $branch->fresh()->cash_balance);

        $sale = Sale::where('shop_id', $branch->id)->latest('id')->first();
        $this->actingAs($branchOwner, 'web')
            ->delete("/app/sales/{$sale->id}", ['reason' => 'test voiding this sale now'])
            ->assertRedirect();

        // stock restored to 10, identically, on BOTH branches
        $this->assertEquals(10, (float) $branchProduct->fresh()->stock);
        $this->assertEquals(10, (float) $mainProduct->fresh()->stock);
        $this->assertEquals($this->stockValue($main), $this->stockValue($branch));

        // cash reversed exactly once
        $this->assertEquals($cashBefore, (float) $branch->fresh()->cash_balance);

        // voided sale excluded from the default (reporting) scope
        $this->assertEquals(0, Sale::where('shop_id', $branch->id)->count());
        $this->assertNotNull($sale->fresh()->voided_at);

        // double-void is blocked, not double-reversed
        $resp = $this->actingAs($branchOwner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'voiding again should be blocked']);
        $this->assertEquals($cashBefore, (float) $branch->fresh()->cash_balance, 'a second void attempt must never reverse cash again');
        $this->assertEquals(10, (float) $branchProduct->fresh()->stock, 'a second void attempt must never restore stock again');
    }

    public function test_voiding_a_due_sale_reduces_the_customers_due_exactly_once(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 500, 'stock' => 5]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Due Customer', 'phone' => '01900000001', 'due' => 0]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [],
        ])->assertOk();
        $this->assertEquals(500, (float) $customer->fresh()->due);

        $sale = Sale::where('shop_id', $shop->id)->latest('id')->first();
        $this->actingAs($owner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'void a due sale'])->assertRedirect();

        $this->assertEquals(0, (float) $customer->fresh()->due, 'voiding a due sale must give the due back exactly once');
        $this->assertEquals(5, (float) $product->fresh()->stock, 'stock must be restored to its pre-sale count');
    }
}
