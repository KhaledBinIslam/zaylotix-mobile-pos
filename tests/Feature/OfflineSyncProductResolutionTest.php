<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Real reported bug — see docs/FIX_PLAN.md. An offline sale queued under
 * one shop (classic trigger: an admin reusing one browser across
 * impersonation sessions for different shops) could sit in the client's
 * IndexedDB past that session ending, then get replayed under a DIFFERENT
 * shop's session. Every product_id in it genuinely doesn't exist under
 * the new tenant scope, giving a misleading generic "product not found"
 * for every line, forever, no matter how many times "retry" was tapped.
 */
class OfflineSyncProductResolutionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_sale_queued_for_a_different_shop_is_rejected_with_a_clear_mismatch_message_not_a_generic_product_error(): void
    {
        [$shopA] = $this->createShopWithOwner(['name' => 'Shop A']);
        [$shopB, $ownerB] = $this->createShopWithOwner(['name' => 'Shop B']);
        $productInShopA = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);

        // simulates trySync() replaying an entry that was queued while shop
        // A's session was active, but is now being synced under shop B's
        $response = $this->actingAs($ownerB, 'web')->postJson('/app/pos/checkout', [
            'shop_id' => $shopA->id,
            'shop_name' => 'Shop A',
            'items' => [['product_id' => $productInShopA->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ]);

        $response->assertStatus(409);
        $response->assertJson(['shop_mismatch' => true]);
        $this->assertStringContainsString('Shop A', $response->json('message'));
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $productInShopA->fresh()->stock); // untouched
    }

    public function test_an_older_queued_entry_with_no_shop_id_still_checks_out_normally(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10]);

        // no shop_id in the payload at all — an entry queued by a build
        // from before this fix existed
        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ]);

        $response->assertOk();
        $this->assertSame(1, Sale::count());
    }

    public function test_a_genuinely_missing_product_names_itself_in_the_error_and_lists_as_a_problem_item(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => 99999, 'qty' => 1, 'product_name' => 'পুরনো পণ্য']],
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('পুরনো পণ্য', $response->json('problem_items.0.product_name'));
        $this->assertSame(99999, $response->json('problem_items.0.product_id'));
    }

    public function test_a_soft_deleted_product_can_still_sync_as_an_offline_replay_but_not_as_a_live_checkout(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Discontinued Item', 'cost' => 100, 'price' => 150, 'stock' => 10]);
        $product->delete(); // soft delete, same as ProductController::destroy()

        // a fresh/live attempt to sell something the shop has since
        // discontinued must still be rejected
        $live = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1, 'product_name' => 'Discontinued Item']],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ]);
        $live->assertStatus(422);
        $this->assertSame(0, Sale::count());

        // but a genuine replay of a sale rung up BEFORE the deletion must
        // still be allowed through — the sale really happened
        $replay = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'offline_replay' => true,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'product_name' => 'Discontinued Item']],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ]);
        $replay->assertOk();
        $this->assertSame(1, Sale::count());
    }

    public function test_a_missing_product_id_falls_back_to_a_same_shop_barcode_match(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10, 'barcode' => '12345']);

        // product_id is wrong/stale, but the barcode snapshot still
        // resolves to the right product within this same shop
        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id + 500, 'qty' => 1, 'product_barcode' => '12345']],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ]);

        $response->assertOk();
        $this->assertSame(1, Sale::count());
        $this->assertEquals(9, $product->fresh()->stock);
    }

    public function test_a_barcode_fallback_never_crosses_into_another_shops_product(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $productInShopA = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 10, 'barcode' => 'shared-code']);

        // shop B's own session; the barcode happens to only exist in shop
        // A's catalog, so it must NOT resolve across the tenant boundary
        $response = $this->actingAs($ownerB, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => 999999, 'qty' => 1, 'product_barcode' => 'shared-code', 'product_name' => 'Rice']],
            'payments' => [['method' => 'cash', 'amount' => 150]],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $productInShopA->fresh()->stock);
    }

    public function test_multiple_problem_items_in_one_cart_are_all_reported_together_not_just_the_first(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $okProduct = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 50, 'price' => 80, 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [
                ['product_id' => $okProduct->id, 'qty' => 1],
                ['product_id' => 11111, 'qty' => 1, 'product_name' => 'Ghost One'],
                ['product_id' => 22222, 'qty' => 1, 'product_name' => 'Ghost Two'],
            ],
            'payments' => [['method' => 'cash', 'amount' => 80]],
        ]);

        $response->assertStatus(422);
        $this->assertCount(2, $response->json('problem_items'));
        $this->assertEqualsCanonicalizing(
            ['Ghost One', 'Ghost Two'],
            array_column($response->json('problem_items'), 'product_name'),
        );
        $this->assertSame(0, Sale::count()); // the whole cart is still atomic — the one good line doesn't partially commit
    }

    public function test_discard_offline_endpoint_logs_an_activity_entry_without_creating_a_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/discard-offline', [
            'reason' => 'কাস্টমার আর নিচ্ছে না',
            'payload' => ['items' => [['product_id' => 1]]],
        ]);

        $response->assertOk();
        $this->assertSame(0, Sale::count());
        $this->assertDatabaseHas('activity_logs', [
            'shop_id' => $shop->id,
            'action' => 'pos.offlineSaleDiscarded',
        ]);
    }

    public function test_product_search_endpoint_is_tenant_scoped(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopA->id, 'name' => 'Only In Shop A', 'cost' => 10, 'price' => 20, 'stock' => 1]);

        $response = $this->actingAs($ownerB, 'web')->getJson('/app/products/search?q=Only');

        $response->assertOk();
        $this->assertCount(0, $response->json('results'));
    }
}
