<?php

namespace Tests\Feature;

use App\Http\Controllers\App\AccountsController;
use App\Models\Customer;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Pool-correctness ledger for RETURN and EXCHANGE (2026-11-23 audit,
 * Baby Park shop 80 + Liberty-Agrabad shop 81, shared_stock_pool). Asserts
 * actual stock/money movement on BOTH the acting shop and its pooled
 * sibling, not just an HTTP status — same contract as ReturnExchangeTest.
 * SELL/VOID have their own ledger test (another fork, same session).
 */
class PoolReturnExchangeLedgerTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** @return array{0: Shop, 1: Shop} [$main, $branch], both with returns + shared_stock_pool */
    private function pooledPair(): array
    {
        [$main] = $this->createShopWithOwner(['cash_balance' => 10000]);
        foreach (['returns', 'product_variants', 'shared_catalog', 'shared_stock_pool'] as $f) {
            $this->grantFeature($main, $f);
        }
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id, 'cash_balance' => 5000]);
        $this->grantFeature($branch, 'returns');

        return [$main, $branch];
    }

    private function makeSale(int $shopId, array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'shop_id' => $shopId, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 200, 'discount' => 0, 'total' => 200, 'profit' => 100, 'payment_mode' => 'cash',
        ], $overrides));
    }

    private function makeItem(Sale $sale, Product $product, array $overrides = []): SaleItem
    {
        return SaleItem::create(array_merge([
            'shop_id' => $sale->shop_id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'unit_factor' => 1, 'qty' => 2, 'price' => 100, 'discount' => 0, 'cost' => 50,
        ], $overrides));
    }

    private function stockValue(int $shopId): float
    {
        $m = new \ReflectionMethod(AccountsController::class, 'stockValue');
        $m->setAccessible(true);
        $prevId = Tenancy::id();
        Tenancy::set($shopId);
        $value = (float) $m->invoke(new AccountsController());
        Tenancy::set($prevId);

        return $value;
    }

    // ---------------------------------------------------------------
    // RETURN
    // ---------------------------------------------------------------

    public function test_resalable_return_at_a_branch_restocks_the_product_and_variant_pool_on_both_sides(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();

        // stock=4 everywhere, as if the pool already had one unit sold out
        // of an original 5 -- the sale itself isn't being tested here
        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Shoe', 'cost' => 500, 'price' => 1000, 'stock' => 4]);
        $mainVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $mainProduct->id, 'size' => '40', 'color' => 'Black', 'stock' => 4, 'cost' => 500]);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->firstOrFail();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('product_id', $branchProduct->id)->firstOrFail();
        $this->assertEquals(4, (float) $mainVariant->stock, 'sanity: mirror hook started both sides at the same figure');

        $sale = $this->makeSale($branch->id, ['total' => 1000, 'subtotal' => 1000]);
        $item = $this->makeItem($sale, $branchProduct, ['product_variant_id' => $branchVariant->id, 'qty' => 1, 'price' => 1000, 'cost' => 500]);

        $this->actingAs($branchOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $this->assertEquals(5, (float) $mainVariant->fresh()->stock, 'master variant restocked');
        $this->assertEquals(5, (float) $branchVariant->fresh()->stock, 'branch variant restocked');
        $this->assertEquals(5, (float) $mainProduct->fresh()->stock);
        $this->assertEquals(5, (float) $branchProduct->fresh()->stock);
        $this->assertEquals($this->stockValue($main->id), $this->stockValue($branch->id), 'pooled stock value must match on both sides');
        $this->assertEquals(4000.0, (float) $branch->fresh()->cash_balance); // 5000 - 1000 (full price paid for 1 unit) refund
    }

    public function test_damaged_return_never_adds_sellable_stock_back_and_writes_off_the_cost(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();

        $mainProduct = Product::create(['shop_id' => $main->id, 'name' => 'Shoe', 'cost' => 500, 'price' => 1000, 'stock' => 4]);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $mainProduct->id)->firstOrFail();

        $sale = $this->makeSale($branch->id, ['total' => 1000, 'subtotal' => 1000]);
        $item = $this->makeItem($sale, $branchProduct, ['qty' => 1, 'price' => 1000, 'cost' => 500]);

        $this->actingAs($branchOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'damaged']],
        ])->assertOk();

        $this->assertEquals(4, (float) $mainProduct->fresh()->stock, 'a damaged return must NEVER add sellable stock back');
        $this->assertEquals(4, (float) $branchProduct->fresh()->stock);
        $dmg = Damage::first();
        $this->assertNotNull($dmg);
        $this->assertEquals(500.0, (float) $dmg->loss);
        $this->assertEquals(4000.0, (float) $branch->fresh()->cash_balance, 'customer is still refunded the full 1000 cash even though the item is written off'); // 5000 - 1000
    }

    public function test_returning_more_than_was_sold_is_rejected(): void
    {
        [$main, $branch] = $this->pooledPair();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $product->id)->firstOrFail();
        $sale = $this->makeSale($branch->id);
        $item = $this->makeItem($sale, $branchProduct, ['qty' => 2]);

        $this->actingAs($branch->users()->first(), 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 3, 'condition' => 'resalable']],
        ])->assertStatus(422);
    }

    /**
     * BUG (confirmed): ReturnController::process() fetches the sale with
     * Sale::withoutGlobalScopes() (needed to bypass the TENANT scope for a
     * cross-branch return — app/Http/Controllers/App/ReturnController.php:384)
     * which also strips Sale's own 'active' global scope
     * (app/Models/Sale.php:60, whereNull('voided_at')) — and nothing in
     * process() re-checks $sale->isVoided() afterward. A return/exchange
     * can be processed against an ALREADY-VOIDED sale: stock still moves,
     * cash/due still moves, a SalesReturn row is still written, all
     * against a bill the owner explicitly cancelled.
     *
     * This test asserts the CORRECT behavior (422, same as
     * SalesController::destroy()'s own blocked-exchange message style) and
     * is expected to FAIL until that check is added — documenting the bug
     * rather than asserting around it.
     */
    public function test_a_return_against_an_already_voided_sale_must_be_rejected(): void
    {
        [$main, $branch] = $this->pooledPair();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Shirt', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $product->id)->firstOrFail();
        $sale = $this->makeSale($branch->id, ['voided_at' => now(), 'voided_reason' => 'test void']);
        $item = $this->makeItem($sale, $branchProduct, ['qty' => 1]);

        $response = $this->actingAs($branch->users()->first(), 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertStatus(422); // EXPECTED TO FAIL — see docblock: the bug returns 200 and moves real stock/cash
    }

    // ---------------------------------------------------------------
    // EXCHANGE
    // ---------------------------------------------------------------

    public function test_exchange_for_a_more_expensive_item_moves_both_products_pool_wide_and_charges_the_difference(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();

        $cheap = Product::create(['shop_id' => $main->id, 'name' => 'Cheap Shirt', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $pricey = Product::create(['shop_id' => $main->id, 'name' => 'Pricey Shirt', 'cost' => 180, 'price' => 350, 'stock' => 4]);
        $branchCheap = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $cheap->id)->firstOrFail();
        $branchPricey = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $pricey->id)->firstOrFail();

        $sale = $this->makeSale($branch->id, ['total' => 200, 'subtotal' => 200]);
        $item = $this->makeItem($sale, $branchCheap, ['qty' => 1, 'price' => 200, 'cost' => 100]);

        $response = $this->actingAs($branchOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $branchPricey->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 150]], // 350 - 200 credit = 150 due
        ]);
        $response->assertOk();

        // returned item back in stock, pool-wide
        $this->assertEquals(4, (float) $cheap->fresh()->stock);
        $this->assertEquals(4, (float) $branchCheap->fresh()->stock);
        // replacement item out of stock, pool-wide, exactly once
        $this->assertEquals(3, (float) $pricey->fresh()->stock);
        $this->assertEquals(3, (float) $branchPricey->fresh()->stock);
        $this->assertEquals($this->stockValue($main->id), $this->stockValue($branch->id));

        $this->assertEquals(5150.0, (float) $branch->fresh()->cash_balance); // 5000 + 150 extra paid
        $exchangeSale = Sale::where('sale_type', 'exchange')->first();
        $this->assertNotNull($exchangeSale);
        $this->assertEquals(350.0, (float) $exchangeSale->total);
        $this->assertEquals(350.0 - 180.0, (float) $exchangeSale->profit, "the NEW item's own profit, its own cost -- never blended with the returned item's");
    }

    public function test_exchange_for_a_cheaper_item_refunds_the_difference_in_cash(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();

        $pricey = Product::create(['shop_id' => $main->id, 'name' => 'Pricey Shirt', 'cost' => 180, 'price' => 350, 'stock' => 3]);
        $cheap = Product::create(['shop_id' => $main->id, 'name' => 'Cheap Shirt', 'cost' => 100, 'price' => 200, 'stock' => 4]);
        $branchPricey = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $pricey->id)->firstOrFail();
        $branchCheap = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $cheap->id)->firstOrFail();

        $sale = $this->makeSale($branch->id, ['total' => 350, 'subtotal' => 350]);
        $item = $this->makeItem($sale, $branchPricey, ['qty' => 1, 'price' => 350, 'cost' => 180]);

        $response = $this->actingAs($branchOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $branchCheap->id, 'qty' => 1]],
        ]);
        $response->assertOk();

        $this->assertEquals(4, (float) $pricey->fresh()->stock);
        $this->assertEquals(4, (float) $branchPricey->fresh()->stock);
        $this->assertEquals(3, (float) $cheap->fresh()->stock);
        $this->assertEquals(3, (float) $branchCheap->fresh()->stock);
        $this->assertEquals($this->stockValue($main->id), $this->stockValue($branch->id));

        // owed 350 - 200 = 150 back, no due on the original sale, so straight to cash
        $this->assertEquals(4850.0, (float) $branch->fresh()->cash_balance); // 5000 - 150
    }

    public function test_exchange_new_item_cost_falls_back_to_product_cost_when_the_variant_has_none(): void
    {
        [$main, $branch] = $this->pooledPair();
        $branchOwner = $branch->users()->first();

        $old = Product::create(['shop_id' => $main->id, 'name' => 'Old', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $new = Product::create(['shop_id' => $main->id, 'name' => 'New', 'cost' => 120, 'price' => 220, 'stock' => 5]);
        $newVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $new->id, 'size' => 'M', 'color' => 'Red', 'stock' => 5]); // no cost/price of its own
        $branchOld = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $old->id)->firstOrFail();
        $branchNew = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $new->id)->firstOrFail();
        $branchNewVariant = ProductVariant::withoutGlobalScopes()->where('product_id', $branchNew->id)->firstOrFail();

        $sale = $this->makeSale($branch->id, ['total' => 200, 'subtotal' => 200]);
        $item = $this->makeItem($sale, $branchOld, ['qty' => 1, 'price' => 200, 'cost' => 100]);

        $this->actingAs($branchOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $branchNew->id, 'product_variant_id' => $branchNewVariant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $exchangeItem = SaleItem::where('sale_id', Sale::where('sale_type', 'exchange')->first()->id)->first();
        $this->assertEquals(120.0, (float) $exchangeItem->cost, 'variant has no cost of its own -- must fall back to the product cost (220 price, 120 cost), not 0');
    }

    /** Confirms correct existing behavior: voiding a bill is blocked while its exchange's replacement sale still stands (SalesController.php:168-170). */
    public function test_voiding_a_sale_that_was_already_exchanged_is_blocked(): void
    {
        [$main, $branch] = $this->pooledPair();
        $this->grantFeature($branch, 'sales_void'); // not actually required for owner, but explicit
        $branchOwner = $branch->users()->first();

        $old = Product::create(['shop_id' => $main->id, 'name' => 'Old', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $new = Product::create(['shop_id' => $main->id, 'name' => 'New', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $branchOld = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $old->id)->firstOrFail();
        $branchNew = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $new->id)->firstOrFail();

        $sale = $this->makeSale($branch->id, ['total' => 200, 'subtotal' => 200]);
        $item = $this->makeItem($sale, $branchOld, ['qty' => 1, 'price' => 200, 'cost' => 100]);
        $this->actingAs($branchOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $branchNew->id, 'qty' => 1]],
        ])->assertOk();

        $response = $this->actingAs($branchOwner, 'web')->delete("/app/sales/{$sale->id}", ['reason' => 'test void after exchange']);
        $response->assertSessionHas('error');
        $this->assertNull($sale->fresh()->voided_at, 'must stay un-voided while the exchange sale still stands');
    }

    public function test_non_pooled_shop_return_and_exchange_still_work_without_a_sibling(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $old = Product::create(['shop_id' => $shop->id, 'name' => 'Old', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $new = Product::create(['shop_id' => $shop->id, 'name' => 'New', 'cost' => 60, 'price' => 120, 'stock' => 3]);
        $sale = $this->makeSale($shop->id, ['total' => 100, 'subtotal' => 100]);
        $item = $this->makeItem($sale, $old, ['qty' => 1, 'price' => 100, 'cost' => 50]);

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $new->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ])->assertOk();

        $this->assertEquals(4, (float) $old->fresh()->stock);
        $this->assertEquals(2, (float) $new->fresh()->stock);
        $this->assertEquals(1020.0, (float) $shop->fresh()->cash_balance);
    }
}
