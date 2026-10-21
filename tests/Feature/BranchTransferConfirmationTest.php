<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 4 item #1, part B — "ব্রাঞ্চ ট্রান্সফার accept/reject" (see
 * docs/FIX_PLAN.md's design note). Opt-in per business (transfer_confirmation
 * feature, granted to the root shop) — a shop without it keeps today's
 * exact instant behavior, proven by BranchStockTransferTest's own 37
 * still-passing tests. This file covers only the NEW pending/accept/reject
 * behavior.
 */
class BranchTransferConfirmationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_a_plain_transfer_holds_both_sides_stock_until_accepted(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);

        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ])->assertRedirect();

        // left the source immediately...
        $this->assertEquals(15, (float) $product->fresh()->stock);
        // ...but hasn't landed at the destination yet
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Rice')->first();
        $this->assertNotNull($destProduct);
        $this->assertEquals(0, (float) $destProduct->stock);

        $transfer = StockTransfer::withoutGlobalScopes()->first();
        $this->assertSame('pending', $transfer->status);
    }

    public function test_destination_owner_accepting_credits_the_destination_stock(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ]);
        $transfer = StockTransfer::withoutGlobalScopes()->first();

        $this->actingAs($branchOwner, 'web')->post(route('app.stockTransfers.accept', $transfer->id))->assertRedirect();

        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Rice')->first();
        $this->assertEquals(5, (float) $destProduct->stock);
        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_destination_owner_rejecting_returns_stock_to_the_source(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ]);
        $transfer = StockTransfer::withoutGlobalScopes()->first();

        $this->actingAs($branchOwner, 'web')->post(route('app.stockTransfers.reject', $transfer->id))->assertRedirect();

        $this->assertEquals(20, (float) $product->fresh()->stock); // back to where it started
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Rice')->first();
        $this->assertEquals(0, (float) $destProduct->stock);
        $this->assertSame('rejected', $transfer->fresh()->status);
    }

    public function test_the_source_branch_cannot_accept_its_own_outgoing_transfer(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ]);
        $transfer = StockTransfer::withoutGlobalScopes()->first();

        $this->actingAs($owner, 'web')->post(route('app.stockTransfers.accept', $transfer->id))->assertStatus(403);
    }

    public function test_an_already_completed_transfer_cannot_be_accepted_again(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ]);
        $transfer = StockTransfer::withoutGlobalScopes()->first();
        $this->actingAs($branchOwner, 'web')->post(route('app.stockTransfers.accept', $transfer->id))->assertRedirect();

        $this->actingAs($branchOwner, 'web')->postJson(route('app.stockTransfers.accept', $transfer->id))->assertStatus(422);

        // stock wasn't double-credited by the second attempt
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Rice')->first();
        $this->assertEquals(5, (float) $destProduct->stock);
    }

    public function test_a_variant_transfer_also_holds_both_sides_until_accepted(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $this->grantFeature($main, 'product_variants');
        $branch = $this->createBranch($main);
        $branchOwner = $branch->users()->first();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Running Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 10]);
        $variant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '42', 'color' => 'Black', 'stock' => 10, 'barcode' => 'SHOE-42-BLK']);

        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => $variant->id, 'qty' => 4]],
        ])->assertRedirect();

        $this->assertEquals(6, (float) $variant->fresh()->stock);
        $transfer = StockTransfer::withoutGlobalScopes()->first();
        $this->assertSame('pending', $transfer->status);

        $this->actingAs($branchOwner, 'web')->post(route('app.stockTransfers.accept', $transfer->id))->assertRedirect();

        $destVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('barcode', 'SHOE-42-BLK')->first();
        $this->assertEquals(4, (float) $destVariant->stock);
    }

    public function test_a_batch_tracked_transfer_stays_instant_even_with_the_feature_on(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'transfer_confirmation');
        $this->grantFeature($main, 'batch_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 20]);
        ProductBatch::create(['shop_id' => $main->id, 'product_id' => $product->id, 'qty' => 20, 'expiry_date' => now()->addDays(90)]);

        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ])->assertRedirect();

        // batch-tracked is deliberately excluded from the hold -- always instant
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Napa')->first();
        $this->assertEquals(5, (float) $destProduct->stock);
        $this->assertSame('completed', StockTransfer::withoutGlobalScopes()->first()->status);
    }

    public function test_without_the_feature_a_transfer_stays_instant_exactly_as_before(): void
    {
        [$main, $owner] = $this->createShopWithOwner(); // feature not granted
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);

        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ])->assertRedirect();

        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Rice')->first();
        $this->assertEquals(5, (float) $destProduct->stock);
        $this->assertSame('completed', StockTransfer::withoutGlobalScopes()->first()->status);
    }
}
