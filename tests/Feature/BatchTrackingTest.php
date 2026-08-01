<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Batch/expiry tracking is a supplementary FEFO layer on top of the
 * product's plain stock count — stock/checkout math never depends on it,
 * it only records which batch units conceptually came from, for expiry
 * visibility and alerts.
 */
class BatchTrackingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_stock_in_with_expiry_creates_a_batch_when_feature_is_on(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 100, 'cost' => 1.2, 'batch_no' => 'B-001', 'expiry_date' => now()->addMonths(6)->toDateString(),
        ])->assertRedirect();

        $this->assertEquals(100, $product->fresh()->stock);
        $this->assertSame(1, ProductBatch::count());
        $batch = ProductBatch::first();
        $this->assertSame('B-001', $batch->batch_no);
        $this->assertSame(100, $batch->qty);
    }

    public function test_stock_in_without_batch_fields_does_not_create_a_batch(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", ['qty' => 50])
            ->assertRedirect();

        $this->assertEquals(50, $product->fresh()->stock);
        $this->assertSame(0, ProductBatch::count());
    }

    public function test_stock_in_never_creates_a_batch_when_the_feature_is_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 100, 'batch_no' => 'B-001', 'expiry_date' => now()->addMonths(6)->toDateString(),
        ])->assertRedirect();

        $this->assertEquals(100, $product->fresh()->stock); // stock still moves normally
        $this->assertSame(0, ProductBatch::count()); // but no batch tracked without the feature
    }

    public function test_checkout_deducts_from_the_soonest_expiring_batch_first(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $soon = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'A', 'expiry_date' => now()->addDays(10), 'qty' => 5, 'cost' => 1]);
        $later = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'B', 'expiry_date' => now()->addDays(60), 'qty' => 20, 'cost' => 1]);
        $product->update(['stock' => 25]);

        // sell 8 — should take all 5 from the soon-expiring batch, then 3 from the later one
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 8]],
            'payments' => [['method' => 'cash', 'amount' => 16]],
        ])->assertOk();

        $this->assertSame(0, $soon->fresh()->qty);
        $this->assertSame(17, $later->fresh()->qty); // 20 - 3
        $this->assertEquals(17, $product->fresh()->stock); // 25 - 8
    }

    public function test_checkout_is_never_blocked_when_tracked_batches_undersupply_the_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        // 50 in stock, but only 10 of it was ever put into a tracked batch
        // (the rest predates batch tracking being turned on)
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 50]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'expiry_date' => now()->addDays(10), 'qty' => 10, 'cost' => 1]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ])->assertOk();

        $this->assertEquals(20, $product->fresh()->stock); // 50 - 30, unaffected by batch shortfall
        $this->assertSame(0, $batch->fresh()->qty); // batch only had 10 to give, floors at 0 not negative
    }

    public function test_checkout_never_touches_batches_when_the_feature_is_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 50]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'expiry_date' => now()->addDays(10), 'qty' => 10, 'cost' => 1]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 10]],
        ])->assertOk();

        $this->assertSame(10, $batch->fresh()->qty); // untouched
    }

    public function test_voiding_a_sale_restores_stock_to_the_exact_batches_it_came_from(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        $soon = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'expiry_date' => now()->addDays(10), 'qty' => 5, 'cost' => 1]);
        $later = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'expiry_date' => now()->addDays(60), 'qty' => 20, 'cost' => 1]);
        $product->update(['stock' => 25]);

        // sell 8 -> 5 from $soon, 3 from $later (same FEFO order as the other test)
        $checkout = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 8]],
            'payments' => [['method' => 'cash', 'amount' => 16]],
        ]);
        $checkout->assertOk();
        $this->assertSame(0, $soon->fresh()->qty);
        $this->assertSame(17, $later->fresh()->qty);

        $saleId = $checkout->json('sale.id');
        $this->actingAs($owner, 'web')->delete("/app/sales/{$saleId}", ['reason' => 'Customer changed their mind'])
            ->assertRedirect();

        // every unit goes back to the exact batch it was taken from, not
        // just added back to whichever batch is soonest-expiring now
        $this->assertSame(5, $soon->fresh()->qty);
        $this->assertSame(20, $later->fresh()->qty);
        $this->assertEquals(25, $product->fresh()->stock);
    }

    public function test_batches_are_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'batch_tracking');
        [$shopB] = $this->createShopWithOwner();
        $productA = Product::create(['shop_id' => $shopA->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        ProductBatch::create(['shop_id' => $shopA->id, 'product_id' => $productA->id, 'qty' => 5, 'expiry_date' => now()->addDays(5)]);
        ProductBatch::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'qty' => 5, 'expiry_date' => now()->addDays(5)]);

        $response = $this->actingAs($ownerA, 'web')->get('/app/stock');

        $response->assertOk();
        $products = $response->getOriginalContent()->getData()['page']['props']['products']['data'];
        $shopAProduct = collect($products)->firstWhere('id', $productA->id);
        $this->assertNotNull($shopAProduct['nearest_batch']);
    }
}
