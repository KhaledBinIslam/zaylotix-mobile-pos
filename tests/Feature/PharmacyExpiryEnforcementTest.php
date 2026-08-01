<?php

namespace Tests\Feature;

use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Pharmacy compliance: an already-expired tracked batch must never leave
 * the shop via a sale — BatchStock::sellableQty() (checked in
 * PosController::checkout before anything is written) and deduct() (which
 * now refuses to draw from an expired batch at all) are what actually
 * enforce that, not just FEFO ordering preference. Damage/return, by
 * contrast, are explicitly allowed to target an expired batch — writing off
 * or returning expired stock is the correct way to remove it.
 */
class PharmacyExpiryEnforcementTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_checkout_is_blocked_when_the_only_covering_stock_sits_in_an_expired_batch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->subDay()]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 10]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, (float) $product->fresh()->stock); // nothing moved
        $this->assertSame(0, Sale::count());
    }

    public function test_checkout_still_succeeds_from_the_non_expired_portion_of_mixed_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 20]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->subDay()]); // expired
        $fresh = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addMonth()]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 6]],
            'payments' => [['method' => 'cash', 'amount' => 12]],
        ]);

        $response->assertOk();
        $this->assertEquals(14, (float) $product->fresh()->stock); // 20 - 6
        $this->assertEquals(4, (float) $fresh->fresh()->qty); // 10 - 6, drawn only from the fresh batch
    }

    public function test_checkout_is_blocked_once_the_request_would_have_to_dip_into_the_expired_batch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 20]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->subDay()]); // expired
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->addMonth()]); // fresh

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 15]], // only 10 is actually sellable
            'payments' => [['method' => 'cash', 'amount' => 30]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(20, (float) $product->fresh()->stock); // nothing moved, atomic rejection
    }

    public function test_untracked_legacy_stock_alongside_an_expired_batch_is_still_sellable(): void
    {
        // stock that predates batch tracking (never given a batch row) has
        // no expiry info at all — sellableQty() must count it as sellable,
        // only the explicitly-expired-and-quantified portion is excluded
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 20]); // 20 total, only 5 ever batched
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 5, 'expiry_date' => now()->subDay()]); // expired

        // sellable = 20 - 5 expired = 15; asserted indirectly below via a
        // checkout that needs more than 15 - 5 would be blocked, but 12 must pass
        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 12]],
            'payments' => [['method' => 'cash', 'amount' => 24]],
        ]);

        $response->assertOk();
        $this->assertEquals(8, (float) $product->fresh()->stock); // 20 - 12
    }

    public function test_damage_can_explicitly_target_an_expired_batch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $this->grantFeature($shop, 'damages');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        $expired = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 10, 'expiry_date' => now()->subDay()]);

        $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id, 'product_batch_id' => $expired->id, 'qty' => 4, 'reason' => 'মেয়াদ শেষ',
        ])->assertRedirect();

        $this->assertEquals(6, (float) $product->fresh()->stock);
        $this->assertEquals(6, (float) $expired->fresh()->qty);
        $this->assertSame($expired->id, Damage::first()->product_batch_id);
    }

    public function test_damage_rejects_a_batch_that_belongs_to_a_different_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $this->grantFeature($shop, 'damages');
        $productA = Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        $productB = Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        $batchB = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $productB->id, 'qty' => 10, 'expiry_date' => now()->addMonth()]);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $productA->id, 'product_batch_id' => $batchB->id, 'qty' => 1,
        ]);

        $response->assertSessionHasErrors('product_batch_id');
        $this->assertEquals(10, (float) $productA->fresh()->stock);
        $this->assertEquals(10, (float) $batchB->fresh()->qty);
    }

    public function test_return_can_target_a_specific_batch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 10]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 5, 'expiry_date' => now()->addMonth()]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 6]],
        ])->assertOk();

        $this->actingAs($owner, 'web')->post('/app/returns', [
            'product_id' => $product->id, 'product_batch_id' => $batch->id, 'qty' => 2, 'refund' => 4,
        ])->assertRedirect();

        $this->assertEquals(9, (float) $product->fresh()->stock); // 10 - 3 + 2
        $this->assertEquals(4, (float) $batch->fresh()->qty); // 5 - 3 (sold) + 2 (returned back in)
        $this->assertSame($batch->id, SalesReturn::first()->product_batch_id);
    }
}
