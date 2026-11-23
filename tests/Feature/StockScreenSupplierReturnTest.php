<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * "Return to supplier" straight from the Stock screen's article sheet —
 * per variant row, or the whole article (every size/color, a qty each) in
 * ONE submission. Both post the exact payload shape the existing
 * SupplierReturnController::store() already takes (lines[] with
 * product_variant_id, force for negative stock, payable/cash/bank
 * settlement), so there is no second backend: stock leaves through
 * applyStockDelta (variant + product, once) and a pooled sibling drops by
 * the same amount, stock value staying equal on both sides.
 */
class StockScreenSupplierReturnTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function shopWithArticle(): array
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0, 'bank_balance' => 0]);
        foreach (['suppliers', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);

        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Bulu', 'payable' => 5000]);
        $article = Product::create(['shop_id' => $shop->id, 'name' => 'L-20001', 'cost' => 650, 'price' => 1400, 'stock' => 0, 'supplier_id' => $supplier->id]);
        $v21 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '21', 'color' => 'BLACK', 'stock' => 2, 'cost' => 700]);
        $v22 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '22', 'color' => 'BLACK', 'stock' => 3, 'cost' => null]); // falls back to 650
        $v23 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $article->id, 'size' => '23', 'color' => 'BLACK', 'stock' => 1, 'cost' => 650]);
        Product::withoutGlobalScopes()->whereKey($article->id)->update(['stock' => 6]);

        return [$shop, $owner, $supplier, $article->fresh(), $v21, $v22, $v23];
    }

    /** (a) one variant row: "21, BLACK — 2 pcs" -> return 1 at its own cost against payable */
    public function test_one_variant_row_returns_against_the_products_supplier_payable(): void
    {
        [$shop, $owner, $supplier, $article, $v21] = $this->shopWithArticle();

        $this->actingAs($owner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier_id' => $supplier->id,
            'settlement_method' => 'payable',
            'reason' => 'defective',
            'lines' => [['product_id' => $article->id, 'product_variant_id' => $v21->id, 'qty' => 1, 'amount' => 700]],
        ])->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, '700.00') && str_contains($msg, 'বকেয়া'));

        $this->assertEquals(1, (float) $v21->fresh()->stock);
        $this->assertEquals(5, (float) $article->fresh()->stock, 'products.stock follows the variant sum, once');
        $this->assertEquals(4300, (float) $supplier->fresh()->payable); // 5000 - 700
        $this->assertEquals(0, (float) $shop->fresh()->cash_balance, 'payable settlement never touches cash');
        $this->assertSame(1, SupplierReturn::count());
        $this->assertDatabaseHas('supplier_returns', ['product_variant_id' => $v21->id, 'qty' => 1, 'amount' => 700, 'reason' => 'defective']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'supplier.return', 'shop_id' => $shop->id]);
    }

    /** (b) whole article: several sizes in ONE submission, blanks (0) skipped by the sheet, mixed own/fallback cost */
    public function test_a_whole_article_returns_several_variants_in_one_submission(): void
    {
        [$shop, $owner, $supplier, $article, $v21, $v22, $v23] = $this->shopWithArticle();

        $this->actingAs($owner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier_id' => $supplier->id,
            'settlement_method' => 'cash',
            'lines' => [
                ['product_id' => $article->id, 'product_variant_id' => $v21->id, 'qty' => 2, 'amount' => 1400], // 2 x 700 (own cost)
                ['product_id' => $article->id, 'product_variant_id' => $v22->id, 'qty' => 3, 'amount' => 1950], // 3 x 650 (article cost)
                // v23 left blank on the sheet -> not sent at all
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $v21->fresh()->stock);
        $this->assertEquals(0, (float) $v22->fresh()->stock);
        $this->assertEquals(1, (float) $v23->fresh()->stock, 'a blank row is untouched');
        $this->assertEquals(1, (float) $article->fresh()->stock);
        $this->assertEquals(3350, (float) $shop->fresh()->cash_balance, 'cash settlement: 1400 + 1950 lands in cash');
        $this->assertEquals(5000, (float) $supplier->fresh()->payable, 'cash settlement never touches payable');
        $this->assertSame(2, SupplierReturn::count());
    }

    /** (c) pooled two-branch: return at branch A -> main and branch B drop by the same qty, stockValue() equal everywhere */
    public function test_a_pooled_return_at_a_branch_keeps_stock_and_stock_value_equal_on_every_member(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branchA, $branchAOwner] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        [$branchB] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        foreach ([$main, $branchA, $branchB] as $s) {
            $this->grantFeature($s, 'product_variants');
            $this->grantFeature($s, 'suppliers');
        }
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');

        // born at the main shop -> the creation hooks put a linked copy with
        // the same stock in both branches
        $master = Product::create(['shop_id' => $main->id, 'name' => 'B-30001', 'cost' => 500, 'price' => 1000, 'stock' => 0]);
        $masterVariant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $master->id, 'size' => '40', 'color' => 'RED', 'stock' => 10, 'cost' => 500]);
        Product::withoutGlobalScopes()->whereKey($master->id)->update(['stock' => 10]);

        $copyA = Product::withoutGlobalScopes()->where('shop_id', $branchA->id)->where('master_product_id', $master->id)->firstOrFail();
        $copyB = Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('master_product_id', $master->id)->firstOrFail();
        $variantA = ProductVariant::withoutGlobalScopes()->where('product_id', $copyA->id)->firstOrFail();
        $variantB = ProductVariant::withoutGlobalScopes()->where('product_id', $copyB->id)->firstOrFail();
        Product::withoutGlobalScopes()->whereIn('id', [$copyA->id, $copyB->id])->update(['stock' => 10]);

        Tenancy::set($branchA->id);
        $supplier = Supplier::create(['shop_id' => $branchA->id, 'name' => 'Sujon', 'payable' => 10000]);

        $this->actingAs($branchAOwner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier_id' => $supplier->id,
            'settlement_method' => 'payable',
            'lines' => [['product_id' => $copyA->id, 'product_variant_id' => $variantA->id, 'qty' => 4, 'amount' => 2000]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        foreach ([[$masterVariant, $master, 'main'], [$variantA, $copyA, 'branch A'], [$variantB, $copyB, 'branch B']] as [$v, $p, $who]) {
            $this->assertEquals(6, (float) ProductVariant::withoutGlobalScopes()->whereKey($v->id)->value('stock'), "{$who} variant: 10 - 4, once");
            $this->assertEquals(6, (float) Product::withoutGlobalScopes()->whereKey($p->id)->value('stock'), "{$who} product: 10 - 4, once");
        }

        // the real Accounts figure, not a re-implementation of it
        $stockValue = new \ReflectionMethod(\App\Http\Controllers\App\AccountsController::class, 'stockValue');
        $stockValue->setAccessible(true);
        $ctrl = new \App\Http\Controllers\App\AccountsController();
        $values = [];
        foreach ([$main, $branchA, $branchB] as $s) {
            Tenancy::set($s->id);
            $values[$s->id] = $stockValue->invoke($ctrl);
        }
        $this->assertCount(1, array_unique($values), 'stock value identical on every pool member: '.json_encode($values));
        $this->assertEquals(3000, $values[$main->id]); // 6 x 500

        // money stays with the shop that did the return
        $this->assertEquals(8000, (float) $supplier->fresh()->payable);
        $this->assertSame(1, SupplierReturn::withoutGlobalScopes()->count());
        $this->assertSame($branchA->id, SupplierReturn::withoutGlobalScopes()->first()->shop_id);
    }

    /** (d) more than is on the shelf: refused with the Bengali message until the owner confirms (force) */
    public function test_returning_more_than_stock_is_refused_until_the_owner_forces_it(): void
    {
        [$shop, $owner, $supplier, $article, $v21] = $this->shopWithArticle(); // v21 has 2

        $payload = [
            'supplier_id' => $supplier->id,
            'settlement_method' => 'payable',
            'lines' => [['product_id' => $article->id, 'product_variant_id' => $v21->id, 'qty' => 5, 'amount' => 3500]],
        ];

        $this->actingAs($owner, 'web')->post(route('app.supplierReturns.store'), $payload)
            ->assertSessionHasErrors('force');
        $this->assertStringContainsString('স্টক মাইনাসে চলে যাবে', session('errors')->first('force'));
        $this->assertEquals(2, (float) $v21->fresh()->stock, 'refused: nothing moved');
        $this->assertEquals(5000, (float) $supplier->fresh()->payable);

        $this->actingAs($owner, 'web')->post(route('app.supplierReturns.store'), $payload + ['force' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(-3, (float) $v21->fresh()->stock, 'forced: goes negative exactly once');
        $this->assertEquals(1, (float) $article->fresh()->stock); // 6 - 5
        $this->assertEquals(1500, (float) $supplier->fresh()->payable); // 5000 - 3500
    }

    /** the route is gated the same way as the More-page entry: purchases perm + suppliers feature */
    public function test_a_shop_without_the_suppliers_feature_cannot_reach_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants'); // suppliers deliberately NOT granted
        Tenancy::set($shop->id);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Plain', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->actingAs($owner, 'web')->post(route('app.supplierReturns.store'), [
            'supplier' => 'Anyone', 'settlement_method' => 'cash',
            'lines' => [['product_id' => $p->id, 'qty' => 1, 'amount' => 10]],
        ])->assertForbidden();
        $this->assertEquals(5, (float) $p->fresh()->stock);
    }
}
