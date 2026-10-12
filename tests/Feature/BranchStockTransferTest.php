<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockTransfer;
use App\Support\CatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Stock transfer between branches of the same business — zero prior test
 * coverage existed for this whole feature before tonight's variant-support
 * fix, found live-broken during a branch audit (a variant product's transfer
 * silently decremented products.stock without touching any ProductVariant
 * row, desyncing products.stock from SUM(variants.stock) on both sides).
 */
class BranchStockTransferTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function createBranch(Shop $main): Shop
    {
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        return $branch;
    }

    public function test_plain_product_transfer_still_works_unchanged(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 20]);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id,
            'product_id' => $product->id,
            'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ]);

        $response->assertRedirect();
        $this->assertEquals(15, (float) $product->fresh()->stock);
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Rice')->first();
        $this->assertNotNull($destProduct);
        $this->assertEquals(5, (float) $destProduct->stock);
    }

    public function test_variant_product_transfer_moves_the_specific_size_on_both_sides(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'product_variants');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Running Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 15]);
        $v42 = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '42', 'color' => 'Black', 'stock' => 10, 'barcode' => 'SHOE-42-BLK', 'cost' => 1000]);
        $v40 = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'Black', 'stock' => 5, 'barcode' => 'SHOE-40-BLK']);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id,
            'product_id' => $product->id,
            'lines' => [['product_variant_id' => $v42->id, 'qty' => 3]],
        ]);

        $response->assertRedirect();

        // source: only the size-42 variant moved, size-40 untouched, product.stock == sum(variants)
        $this->assertEquals(7, (float) $v42->fresh()->stock);
        $this->assertEquals(5, (float) $v40->fresh()->stock);
        $this->assertEquals(12, (float) $product->fresh()->stock);

        // destination: a matching size-42 variant was created (same barcode/price/cost), stock == what was sent
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Running Shoe')->first();
        $this->assertEquals(3, (float) $destProduct->stock);
        $destVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $destProduct->id)->first();
        $this->assertNotNull($destVariant);
        $this->assertEquals('42', $destVariant->size);
        $this->assertEquals('SHOE-42-BLK', $destVariant->barcode);
        $this->assertEquals(3, (float) $destVariant->stock);
        $this->assertEquals(1000.0, (float) $destVariant->cost);

        // history shows which size moved
        $transfer = StockTransfer::first();
        $this->assertSame('42, Black', $transfer->variant_label);
        $this->assertEquals(3, (float) $transfer->qty);
    }

    public function test_second_transfer_of_the_same_size_lands_on_the_already_created_destination_variant(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'product_variants');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Running Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 10]);
        $v42 = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '42', 'color' => 'Black', 'stock' => 10, 'barcode' => 'SHOE-42-BLK']);

        $payload = ['to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => $v42->id, 'qty' => 3]]];
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', $payload)->assertRedirect();
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', $payload)->assertRedirect();

        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Running Shoe')->first();
        $this->assertEquals(1, ProductVariant::withoutGlobalScopes()->where('product_id', $destProduct->id)->count()); // not duplicated
        $this->assertEquals(6, (float) $destProduct->fresh()->stock);
        $this->assertEquals(4, (float) $v42->fresh()->stock);
    }

    public function test_a_variant_product_cannot_transfer_without_choosing_a_variant(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'product_variants');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Running Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 10]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 3]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, (float) $product->fresh()->stock); // unchanged
    }

    public function test_cannot_send_more_than_a_variants_own_stock(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'product_variants');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Running Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 10]);
        $v42 = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => $v42->id, 'qty' => 50]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, (float) $v42->fresh()->stock);
    }

    /** Double-tap safety: sending the exact remaining stock twice in a row must only succeed once. */
    public function test_a_second_identical_transfer_is_rejected_once_stock_is_exhausted(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 5]);

        $payload = ['to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 5]]];
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', $payload)->assertRedirect();
        $this->actingAs($owner, 'web')->postJson('/app/stock-transfers', $payload)->assertStatus(422);

        $this->assertEquals(0, (float) $product->fresh()->stock);
    }

    public function test_a_batch_tracked_product_transfers_and_the_batch_identity_follows_it(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'batch_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 100]);
        $batch = ProductBatch::create(['shop_id' => $main->id, 'product_id' => $product->id, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString(), 'qty' => 100, 'cost' => 2]);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 10]],
        ]);

        $response->assertRedirect();
        $this->assertEquals(90, (float) $product->fresh()->stock);
        $this->assertEquals(90, (float) $batch->fresh()->qty);

        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Napa')->first();
        $this->assertEquals(10, (float) $destProduct->stock);
        $destBatch = ProductBatch::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $destProduct->id)->first();
        $this->assertNotNull($destBatch);
        $this->assertSame('B1', $destBatch->batch_no);
        $this->assertSame($batch->expiry_date->toDateString(), $destBatch->expiry_date->toDateString());
        $this->assertEquals(10, (float) $destBatch->qty);
        $this->assertEquals(2.0, (float) $destBatch->cost);
    }

    public function test_batch_transfer_draws_fefo_across_two_batches_and_merges_into_one_destination_batch_on_a_repeat(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'batch_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 15]);
        $soonest = ProductBatch::create(['shop_id' => $main->id, 'product_id' => $product->id, 'batch_no' => 'OLD', 'expiry_date' => now()->addMonth()->toDateString(), 'qty' => 5, 'cost' => 2]);
        $later = ProductBatch::create(['shop_id' => $main->id, 'product_id' => $product->id, 'batch_no' => 'NEW', 'expiry_date' => now()->addYear()->toDateString(), 'qty' => 10, 'cost' => 2]);

        // first transfer (qty 8) exhausts the soonest-expiring batch (5) and spills 3 into the later one
        $payload = ['to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 8]]];
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', $payload)->assertRedirect();

        $this->assertEquals(0, (float) $soonest->fresh()->qty);
        $this->assertEquals(7, (float) $later->fresh()->qty);

        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Napa')->first();
        $this->assertEquals(2, ProductBatch::withoutGlobalScopes()->where('product_id', $destProduct->id)->count());

        // second transfer (qty 2) draws only from NEW (OLD is empty) and lands on the SAME destination batch, not a duplicate
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', ['to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 2]]])->assertRedirect();

        $this->assertEquals(5, (float) $later->fresh()->qty);
        $this->assertEquals(2, ProductBatch::withoutGlobalScopes()->where('product_id', $destProduct->id)->count()); // still just 2, not 3
        $destNewBatch = ProductBatch::withoutGlobalScopes()->where('product_id', $destProduct->id)->where('batch_no', 'NEW')->first();
        $this->assertEquals(5, (float) $destNewBatch->qty); // 3 from the first transfer's spillover + 2 from the second
    }

    public function test_batch_transfer_never_draws_from_an_expired_batch(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'batch_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 10]);
        $expired = ProductBatch::create(['shop_id' => $main->id, 'product_id' => $product->id, 'batch_no' => 'EXP', 'expiry_date' => now()->subDay()->toDateString(), 'qty' => 10, 'cost' => 2]);

        // the whole qty is sitting in an expired batch, but products.stock itself doesn't know that —
        // the transfer still succeeds (same "never blocks" rule as a sale would hit via sellableQty elsewhere),
        // it just moves as plain stock since no non-expired batch exists to draw from
        $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 10]],
        ])->assertRedirect();

        $this->assertEquals(10, (float) $expired->fresh()->qty); // untouched — never drawn from
        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Napa')->first();
        $this->assertEquals(10, (float) $destProduct->stock);
        $this->assertEquals(0, ProductBatch::withoutGlobalScopes()->where('product_id', $destProduct->id)->count()); // no batch created for it
    }

    public function test_a_serial_tracked_product_transfers_the_specific_imeis_picked(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'serial_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 2]);
        $moved = ProductSerial::create(['shop_id' => $main->id, 'product_id' => $product->id, 'imei' => '111', 'status' => 'in_stock', 'warranty_expiry' => now()->addYear()]);
        $stays = ProductSerial::create(['shop_id' => $main->id, 'product_id' => $product->id, 'imei' => '222', 'status' => 'in_stock']);

        $response = $this->actingAs($owner, 'web')->post('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['serial_ids' => [$moved->id]]],
        ]);

        $response->assertRedirect();
        $this->assertEquals(1, (float) $product->fresh()->stock);
        $this->assertEquals(1, ProductSerial::where('product_id', $product->id)->count()); // only '222' left on the source

        $destProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Phone')->first();
        $this->assertEquals(1, (float) $destProduct->stock);
        $movedFresh = $moved->fresh();
        $this->assertEquals($branch->id, $movedFresh->shop_id);
        $this->assertEquals($destProduct->id, $movedFresh->product_id);
        $this->assertSame('111', $movedFresh->imei); // same unit, same warranty history, just a new owner
        $this->assertTrue($movedFresh->isUnderWarranty());
        $this->assertEquals($main->id, $stays->fresh()->shop_id); // '222' never touched
    }

    public function test_an_already_sold_serial_cannot_be_transferred(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'serial_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 1]);
        $sold = ProductSerial::create(['shop_id' => $main->id, 'product_id' => $product->id, 'imei' => '111', 'status' => 'sold']);

        $response = $this->actingAs($owner, 'web')->postJson('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['serial_ids' => [$sold->id]]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals($main->id, $sold->fresh()->shop_id); // untouched
    }

    public function test_a_serial_tracked_product_requires_picking_at_least_one_imei(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'serial_tracking');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 1]);
        ProductSerial::create(['shop_id' => $main->id, 'product_id' => $product->id, 'imei' => '111', 'status' => 'in_stock']);

        $response = $this->actingAs($owner, 'web')->postJson('/app/stock-transfers', [
            'to_shop_id' => $branch->id, 'product_id' => $product->id, 'lines' => [['serial_ids' => []]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, (float) $product->fresh()->stock);
    }

    public function test_cannot_transfer_to_a_shop_outside_the_business(): void
    {
        [$main, $owner] = $this->createShopWithOwner();
        [$unrelated] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/stock-transfers', [
            'to_shop_id' => $unrelated->id, 'product_id' => $product->id, 'lines' => [['product_variant_id' => null, 'qty' => 5]],
        ]);

        $response->assertStatus(403);
        $this->assertEquals(10, (float) $product->fresh()->stock);
    }

    public function test_catalog_sync_copies_variants_without_ever_touching_branch_stock(): void
    {
        [$main] = $this->createShopWithOwner();
        $this->grantFeature($main, 'product_variants');
        $branch = $this->createBranch($main);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Running Shoe', 'cost' => 1000, 'price' => 2000, 'stock' => 15]);
        ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '42', 'color' => 'Black', 'stock' => 10, 'barcode' => 'SHOE-42-BLK', 'price' => 2200]);

        CatalogSync::syncToBranch($main->fresh(), $branch->fresh());

        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('name', 'Running Shoe')->first();
        $this->assertNotNull($branchProduct);
        $this->assertEquals(0, (float) $branchProduct->stock); // never overwritten/pre-filled
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('shop_id', $branch->id)->where('product_id', $branchProduct->id)->first();
        $this->assertNotNull($branchVariant);
        $this->assertSame('SHOE-42-BLK', $branchVariant->barcode); // same barcode, shared across the business on purpose
        $this->assertEquals(2200.0, (float) $branchVariant->price);
        $this->assertEquals(0, (float) $branchVariant->stock);

        // re-syncing after a price change updates the branch variant's price, still never its stock
        ProductVariant::withoutGlobalScopes()->where('shop_id', $main->id)->where('product_id', $product->id)->first()->update(['price' => 2500]);
        $branchVariant->update(['stock' => 4]); // simulate the branch having since received stock independently
        CatalogSync::syncToBranch($main->fresh(), $branch->fresh());
        $this->assertEquals(2500.0, (float) $branchVariant->fresh()->price);
        $this->assertEquals(4, (float) $branchVariant->fresh()->stock); // untouched
    }

    public function test_branch_subscription_is_checked_independently_of_the_owners_home_shop(): void
    {
        [$main, $owner] = $this->createShopWithOwner(['subscription_expiry' => now()->addMonth()->toDateString()]);
        $branch = $this->createBranch($main);
        $branch->update(['subscription_expiry' => now()->subDay()->toDateString()]); // this branch's own subscription has lapsed

        // still on the main shop (active) — works
        $this->actingAs($owner, 'web')->get('/app/home')->assertOk();

        // switch into the lapsed branch
        $this->actingAs($owner, 'web')->post('/app/branches/'.$branch->id.'/switch')->assertRedirect();

        // the branch's own lapsed subscription must now block, even though the home shop is fine
        $response = $this->actingAs($owner, 'web')->get('/app/home');
        $response->assertRedirect(route('login'));
    }
}
