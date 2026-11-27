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
 * A bill is returned or exchanged at the counter that SOLD it (Khaled, 2026-11-27: "j dokan theke sell hoiche ei
 * dokan theke exchange korte hobe"). A sibling branch can still FIND the bill, so the cashier can see where to send
 * the customer, but cannot process it: the refund would leave the wrong till and the loss would land in the wrong
 * branch's books. (Branch phase 2 used to allow it; those tests were replaced by these.) Same-shop return/exchange
 * (ReturnExchangeTest) is unchanged.
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

    /**
     * Real live bug (Baby Park): the lookup response's per-item 'barcode'
     * field — what Returns/Index.vue scans against to pick a line — used
     * $item->variant?->barcode / $item->product?->barcode, plain BelongsTo
     * relations that still carry the default tenant scope even though the
     * Sale/SaleItem themselves were fetched withoutGlobalScopes(). Looked
     * up from the branch B cashier's side (the branch, not the sale's own
     * origin), that silently returned null, so scanning never matched
     * anything on a cross-branch return — exactly the scenario this whole
     * screen exists for.
     */
    public function test_lookup_from_a_sibling_branch_still_exposes_the_items_own_barcode(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main);
        $branchB = $this->createBranch($main);
        $product = Product::create(['shop_id' => $branchA->id, 'name' => 'Shoe', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $variant = ProductVariant::create([
            'shop_id' => $branchA->id, 'product_id' => $product->id,
            'size' => '42', 'color' => 'Black', 'barcode' => 'D-L-10041-RXXZ-BLACK-42', 'stock' => 3,
        ]);
        $sale = $this->makeSale($branchA->id, ['invoice_no' => 'INV-CROSSBARCODE']);
        $this->makeItem($sale, $product, ['product_variant_id' => $variant->id, 'variant_label' => '42 / Black', 'qty' => 1]);
        $branchBOwner = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        \App\Support\Tenancy::set($branchB->id);
        $response = $this->actingAs($branchBOwner, 'web')->getJson('/app/returns/lookup?q=INV-CROSSBARCODE');

        $response->assertOk();
        $this->assertSame('D-L-10041-RXXZ-BLACK-42', $response->json('sales.0.items.0.barcode'));
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

    /** Two branches of one business, a bill sold at A, and the owners of both. */
    private function aBillAtBranchA(array $stock = ['stock' => 3]): array
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'returns');
        $branchA = $this->createBranch($main, ['cash_balance' => 1000, 'name' => 'Baby Park', 'area' => 'শাখা ২']);
        $branchB = $this->createBranch($main, ['cash_balance' => 500, 'name' => 'Baby Park']);
        $productA = Product::create(array_merge(['shop_id' => $branchA->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'barcode' => 'SHIRT-1'], $stock));
        $sale = $this->makeSale($branchA->id, ['subtotal' => 300, 'total' => 300]);
        $item = $this->makeItem($sale, $productA, ['qty' => 3, 'price' => 100, 'cost' => 50]);
        $ownerA = User::withoutGlobalScopes()->where('shop_id', $branchA->id)->first();
        $ownerB = User::withoutGlobalScopes()->where('shop_id', $branchB->id)->first();

        return [$branchA, $branchB, $productA, $sale, $item, $ownerA, $ownerB];
    }

    private function nothingMovedAt(Shop $a, Shop $b, Product $p, float $stockA = 3): void
    {
        $this->assertEquals($stockA, (float) $p->fresh()->stock, 'no stock moved');
        $this->assertEquals(1000.0, (float) $a->fresh()->cash_balance, 'the selling till is untouched');
        $this->assertEquals(500.0, (float) $b->fresh()->cash_balance, 'the other till is untouched');
        $this->assertSame(0, SalesReturn::withoutGlobalScopes()->count(), 'no return was recorded');
        $this->assertSame(0, Sale::withoutGlobalScopes()->where('sale_type', 'exchange')->count(), 'no exchange bill was made');
    }

    public function test_a_refund_at_a_sibling_branch_is_refused_and_names_the_selling_shop(): void
    {
        [$a, $b, $product, $sale, $item, , $ownerB] = $this->aBillAtBranchA();

        $r = $this->actingAs($ownerB, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('Baby Park — শাখা ২', (string) $r->json('message'), 'the message tells the cashier WHICH shop to go to');
        $this->assertStringContainsString('ওই দোকান থেকেই', (string) $r->json('message'));
        $this->nothingMovedAt($a, $b, $product);
    }

    public function test_an_exchange_at_a_sibling_branch_is_refused_and_nothing_is_sold(): void
    {
        [$a, $b, $product, $sale, $item, , $ownerB] = $this->aBillAtBranchA();
        $newProduct = Product::create(['shop_id' => $b->id, 'name' => 'Shirt L', 'cost' => 60, 'price' => 150, 'stock' => 5]);

        $this->actingAs($ownerB, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'exchange',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
            'new_items' => [['product_id' => $newProduct->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertStatus(422);

        $this->nothingMovedAt($a, $b, $product);
        $this->assertEquals(5, (float) $newProduct->fresh()->stock, 'the replacement stays on the shelf');
    }

    public function test_a_damaged_return_at_a_sibling_branch_writes_nothing_off(): void
    {
        [$a, $b, $product, $sale, $item, , $ownerB] = $this->aBillAtBranchA();

        $this->actingAs($ownerB, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'damaged']],
        ])->assertStatus(422);

        $this->assertSame(0, Damage::withoutGlobalScopes()->count());
        $this->nothingMovedAt($a, $b, $product);
    }

    public function test_the_selling_branch_takes_it_back_normally_after_the_sibling_was_refused(): void
    {
        [$a, $b, $product, $sale, $item, $ownerA, $ownerB] = $this->aBillAtBranchA();
        $line = ['sale_id' => $sale->id, 'action' => 'refund', 'lines' => [['sale_item_id' => $item->id, 'qty' => 2, 'condition' => 'resalable']]];

        $this->actingAs($ownerB, 'web')->postJson('/app/returns/process', $line)->assertStatus(422);
        $r = $this->actingAs($ownerA, 'web')->postJson('/app/returns/process', $line);

        $r->assertOk();
        $this->assertEquals(200.0, $r->json('total_refund'));
        $this->assertEquals(5, (float) $product->fresh()->stock, "back on the selling branch's shelf (3 + 2)");
        $this->assertEquals(800.0, (float) $a->fresh()->cash_balance, 'the refund left the SELLING till');
        $this->assertEquals(500.0, (float) $b->fresh()->cash_balance, 'the other till never moved');
        $this->assertEquals($a->id, (int) SalesReturn::withoutGlobalScopes()->first()->shop_id, 'the return belongs to the shop that sold it');
    }

    public function test_a_second_return_of_what_is_left_still_works_at_the_selling_branch(): void
    {
        [, , , $sale, $item, $ownerA, $ownerB] = $this->aBillAtBranchA();
        $post = fn ($who, int $qty) => $this->actingAs($who, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id, 'action' => 'refund', 'lines' => [['sale_item_id' => $item->id, 'qty' => $qty, 'condition' => 'resalable']],
        ]);

        $post($ownerA, 2)->assertOk();
        $post($ownerB, 1)->assertStatus(422);   // refused for being the wrong counter, not because of quantity
        $post($ownerA, 2)->assertStatus(422);   // only 1 left returnable
        $post($ownerA, 1)->assertOk();
    }

    public function test_the_lookup_marks_a_sibling_bill_as_not_processable_here_and_the_own_bill_as_processable(): void
    {
        [, , , $sale, , $ownerA, $ownerB] = $this->aBillAtBranchA();

        $here = $this->actingAs($ownerA, 'web')->getJson('/app/returns/lookup?q='.$sale->invoice_no)->assertOk();
        $this->assertTrue($here->json('sales.0.can_process_here'));
        $this->assertNull($here->json('sales.0.blocked_message'));

        $there = $this->actingAs($ownerB, 'web')->getJson('/app/returns/lookup?q='.$sale->invoice_no)->assertOk();
        $this->assertFalse($there->json('sales.0.can_process_here'));
        $this->assertStringContainsString('Baby Park — শাখা ২', (string) $there->json('sales.0.blocked_message'));
        $this->assertSame('Baby Park — শাখা ২', $there->json('sales.0.origin_shop_name'), 'two branches share a name — the area tells them apart');
    }
}
