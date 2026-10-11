<?php

namespace Tests\Feature;

use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Branch phase 2: a branch can take back a sale made at a sibling branch —
 * stock goes back into the RECEIVING branch's own inventory, and cash/due
 * settles from the RECEIVING branch's own books, never the sale's origin
 * shop (Khaled's explicit spec). Existing same-shop return/exchange
 * behavior (ReturnExchangeTest, all passing unchanged) is the far more
 * common path and stays byte-for-byte identical — this file only covers
 * the genuinely cross-branch case.
 */
class BranchCrossReturnTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main, array $attrs = []): Shop
    {
        [$branch] = $this->createShopWithOwner(array_merge(['parent_shop_id' => $main->id], $attrs));
        // feature grants are per-shop, even for a branch (see tonight's
        // branch audit) — CreatesShops' createShopWithOwner() doesn't copy
        // anything from $main, unlike the real Admin\ShopController::
        // createBranch() this test deliberately bypasses for speed
        $this->grantFeature($branch, 'returns');

        return $branch;
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

    public function test_a_branch_can_look_up_a_sale_made_at_a_sibling_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $product = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($branchA->id, ['invoice_no' => 'INV-CROSS1']);
        $this->makeItem($sale, $product, ['qty' => 1]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        \App\Support\Tenancy::set($branchB->id);
        $response = $this->actingAs($branchBOwner, 'web')->getJson('/app/returns/lookup?q=INV-CROSS1');

        $response->assertOk();
        $this->assertCount(1, $response->json('sales'));
        $this->assertSame('INV-CROSS1', $response->json('sales.0.invoice_no'));
    }

    public function test_refund_processed_at_a_sibling_branch_restocks_and_pays_from_that_branch_not_the_origin(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $productA = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3, 'barcode' => 'SHIRT-1']);
        $sale = $this->makeSale($branchA->id);
        $item = $this->makeItem($sale, $productA, ['qty' => 1, 'price' => 100, 'cost' => 50]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        $response = $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertOk();
        $this->assertEquals(100.0, $response->json('total_refund'));

        // branch A (the origin) untouched
        $this->assertEquals(3, (float) $productA->fresh()->stock);
        $this->assertEquals(1000.0, (float) $branchA->fresh()->cash_balance);

        // branch B (the receiver) got the stock back and paid the refund
        $branchBProduct = Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('barcode', 'SHIRT-1')->first();
        $this->assertNotNull($branchBProduct);
        $this->assertEquals(1, (float) $branchBProduct->stock); // auto-created at 0, +1
        $this->assertEquals(400.0, (float) $branchB->fresh()->cash_balance); // 500 - 100
    }

    public function test_refund_at_a_sibling_branch_reuses_an_existing_matching_product_not_a_duplicate(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $productA = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3, 'barcode' => 'SHIRT-1']);
        $productB = Product::create(['shop_id' => $branchB->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 5, 'barcode' => 'SHIRT-1']);
        $sale = $this->makeSale($branchA->id);
        $item = $this->makeItem($sale, $productA, ['qty' => 1, 'price' => 100, 'cost' => 50]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $this->assertEquals(6, (float) $productB->fresh()->stock); // 5 + 1, same row
        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('barcode', 'SHIRT-1')->count());
    }

    public function test_variant_refund_at_a_sibling_branch_restocks_the_matching_variant(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $this->grantFeature($main, 'product_variants');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $this->grantFeature($branchA, 'product_variants');
        $this->grantFeature($branchB, 'product_variants');
        $productA = Product::create(['shop_id' => $branchA->id, 'name' => 'Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 10]);
        $variantA = ProductVariant::create(['shop_id' => $branchA->id, 'product_id' => $productA->id, 'size' => '42', 'color' => 'Black', 'stock' => 10, 'barcode' => 'SHOE-42-BLK']);
        $sale = $this->makeSale($branchA->id, ['subtotal' => 2000, 'total' => 2000]);
        $item = $this->makeItem($sale, $productA, ['qty' => 1, 'price' => 2000, 'cost' => 1000, 'product_variant_id' => $variantA->id, 'variant_label' => '42, Black']);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $branchBProduct = Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('name', 'Shoe')->first();
        $this->assertNotNull($branchBProduct);
        $branchBVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('product_id', $branchBProduct->id)->first();
        $this->assertNotNull($branchBVariant);
        $this->assertSame('SHOE-42-BLK', $branchBVariant->barcode);
        $this->assertEquals(1, (float) $branchBVariant->stock);
        $this->assertEquals(1, (float) $branchBProduct->stock);

        // origin branch's own variant/product untouched
        $this->assertEquals(10, (float) $variantA->fresh()->stock);
    }

    public function test_a_prior_return_at_one_branch_counts_against_returnable_qty_when_returning_more_at_another(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $productA = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($branchA->id, ['subtotal' => 300, 'total' => 300]);
        $item = $this->makeItem($sale, $productA, ['qty' => 3, 'price' => 100, 'cost' => 50]);
        $branchAOwner = User::withoutGlobalScopes()->where('shop_id', $branchA->id)->first();
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        // return 2 of 3 at branch A itself
        $this->actingAs($branchAOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 2, 'condition' => 'resalable']],
        ])->assertOk();

        // trying to return 2 more at branch B must fail — only 1 left returnable
        $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 2, 'condition' => 'resalable']],
        ])->assertStatus(422);

        // returning the remaining 1 at branch B succeeds
        $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();
    }

    public function test_exchange_at_a_sibling_branch_creates_the_new_sale_at_the_receiving_branch(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $oldProduct = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt M', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $newProduct = Product::create(['shop_id' => $branchB->id, 'name' => 'Shirt L', 'cost' => 60, 'price' => 150, 'stock' => 5]);
        $sale = $this->makeSale($branchA->id);
        $item = $this->makeItem($sale, $oldProduct, ['qty' => 1, 'price' => 100, 'cost' => 50]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        $response = $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $newProduct->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ]);

        $response->assertOk();
        $exchangeSale = Sale::withoutGlobalScopes()->where('sale_type', 'exchange')->first();
        $this->assertNotNull($exchangeSale);
        $this->assertSame($branchB->id, $exchangeSale->shop_id); // created at the RECEIVING branch
        $this->assertEquals(4, (float) $newProduct->fresh()->stock); // 5 - 1, taken from branch B's own stock
        $this->assertEquals(550.0, (float) $branchB->fresh()->cash_balance); // 500 + 50 collected
        $this->assertEquals(1000.0, (float) $branchA->fresh()->cash_balance); // origin untouched
    }

    public function test_a_batch_tracked_items_sale_cannot_be_returned_cross_branch_yet(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $this->grantFeature($branchA, 'batch_tracking');
        $productA = Product::create(['shop_id' => $branchA->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 100]);
        ProductBatch::create(['shop_id' => $branchA->id, 'product_id' => $productA->id, 'batch_no' => 'B1', 'qty' => 50, 'cost' => 2]);
        $sale = $this->makeSale($branchA->id, ['subtotal' => 30, 'total' => 30]);
        $item = $this->makeItem($sale, $productA, [
            'qty' => 10, 'price' => 3, 'cost' => 2,
            'batch_allocations' => [['batch_id' => 1, 'qty' => 10]],
        ]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        $response = $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 5, 'condition' => 'resalable']],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(100, (float) $productA->fresh()->stock); // untouched
    }

    public function test_damaged_condition_cross_branch_logs_against_the_receiving_branchs_own_product(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $branchB = $this->createBranch($main, ['cash_balance' => 500]);
        $productA = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($branchA->id);
        $item = $this->makeItem($sale, $productA, ['qty' => 1, 'price' => 100, 'cost' => 50]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        $this->actingAs($branchBOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'damaged']],
        ])->assertOk();

        $this->assertEquals(3, (float) $productA->fresh()->stock); // damaged — origin untouched regardless
        $damage = Damage::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();
        $this->assertNotNull($damage);
        $branchBProduct = Product::withoutGlobalScopes()->where('shop_id', $branchB->id)->where('name', 'Shirt')->first();
        $this->assertSame($branchBProduct->id, $damage->product_id);
    }

    /** Tenant isolation must still hold — a genuinely unrelated shop (not a sibling) can't find or touch this sale at all. */
    public function test_an_unrelated_shop_cannot_find_or_return_against_another_businesss_sale(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000]);
        $product = Product::create(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = $this->makeSale($branchA->id, ['invoice_no' => 'INV-UNRELATED']);
        $item = $this->makeItem($sale, $product, ['qty' => 1]);
        [$unrelated, $unrelatedOwner] = $this->createShopWithOwner(['cash_balance' => 500]);
        $this->grantFeature($unrelated, 'returns');

        $lookup = $this->actingAs($unrelatedOwner, 'web')->getJson('/app/returns/lookup?q=INV-UNRELATED');
        $lookup->assertOk();
        $this->assertCount(0, $lookup->json('sales'));

        $process = $this->actingAs($unrelatedOwner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);
        $process->assertStatus(404);
        $this->assertEquals(500.0, (float) $unrelated->fresh()->cash_balance); // untouched
    }
}
