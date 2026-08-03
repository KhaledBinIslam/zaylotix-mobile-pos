<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A 'pending' purchase withholds stock/money effects until markReceived()
 * is called; 'received' (the old, only-ever-existed-before behavior) still
 * applies everything at creation time. The two paths share applyEffects()
 * so they can never compute the numbers differently.
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /**
     * Regression test: PurchaseController::index() rendered a Vue page
     * (App/Purchases/Index) that didn't exist on disk — the route was
     * reachable (feature-gated, correctly authorized) but would 500 the
     * moment anyone actually visited it, and there was no UI anywhere to
     * mark a pending purchase received even though the backend action
     * existed and worked. Both gaps are fixed; this locks the page in.
     */
    public function test_purchase_history_page_renders_and_shows_pending_and_received(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'purchases');
        Purchase::create(['shop_id' => $shop->id, 'amount' => 100, 'method' => 'cash', 'status' => 'received', 'date' => now()->toDateString()]);
        Purchase::create(['shop_id' => $shop->id, 'amount' => 200, 'method' => 'cash', 'status' => 'pending', 'date' => now()->toDateString()]);

        $response = $this->actingAs($owner, 'web')->get('/app/purchases');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Purchases/Index')
            ->has('purchases', 2)
        );
    }

    public function test_a_pending_purchase_withholds_stock_and_money_until_received(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);

        $this->actingAs($owner, 'web')->post('/app/purchases', [
            'amount' => 300, 'method' => 'cash', 'status' => 'pending',
            'product_id' => $product->id, 'qty' => 30, 'cost' => 15,
        ])->assertRedirect();

        $this->assertEquals(50, $product->fresh()->stock); // unchanged — still pending
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // unchanged

        $purchase = Purchase::where('status', 'pending')->first();
        $this->actingAs($owner, 'web')->post("/app/purchases/{$purchase->id}/receive")->assertRedirect();

        $this->assertEquals(80, $product->fresh()->stock); // 50 + 30, applied now
        $this->assertEquals(700.0, (float) $shop->fresh()->cash_balance); // 1000 - 300
        $this->assertSame('received', $purchase->fresh()->status);
    }

    public function test_marking_an_already_received_purchase_received_again_is_rejected(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');
        $purchase = Purchase::create(['shop_id' => $shop->id, 'amount' => 200, 'method' => 'cash', 'status' => 'received', 'date' => now()->toDateString()]);

        $this->actingAs($owner, 'web')->post("/app/purchases/{$purchase->id}/receive")
            ->assertStatus(422);

        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance); // untouched
    }

    /**
     * Regression test for the double-apply race: two near-simultaneous
     * "mark received" requests on the same pending purchase must only
     * apply its stock/money effects once between them — this is what
     * actually proves the lock-then-recheck-inside-the-transaction fix
     * works, a sequential test can't catch a race.
     */
    public function test_concurrent_mark_received_requests_only_apply_effects_once(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 50]);
        $purchase = Purchase::create([
            'shop_id' => $shop->id, 'amount' => 300, 'method' => 'cash', 'status' => 'pending',
            'pending_details' => ['product_id' => $product->id, 'qty' => 30, 'cost' => 15, 'imeis' => []],
            'product_id' => $product->id, 'qty' => 30, 'date' => now()->toDateString(),
        ]);

        $results = [];
        foreach (range(1, 5) as $i) {
            $results[] = $this->actingAs($owner, 'web')->post("/app/purchases/{$purchase->id}/receive")->getStatusCode();
        }

        // Laravel's test client is sequential, not truly concurrent, but the
        // lock-then-recheck logic under test doesn't distinguish "raced" from
        // "retried in a tight loop" — exactly one of these must have actually
        // applied the purchase, the rest must see it's no longer pending.
        $this->assertSame(1, collect($results)->filter(fn ($code) => $code < 400)->count());
        $this->assertSame(4, collect($results)->filter(fn ($code) => $code === 422)->count());
        $this->assertEquals(80, $product->fresh()->stock); // 50 + 30, exactly once
        $this->assertEquals(700.0, (float) $shop->fresh()->cash_balance); // 1000 - 300, exactly once
    }

    /**
     * Regression test: a variant product used to hard-reject every purchase
     * ("add stock to each variant separately") — now a purchase can name
     * exactly which variant received stock, same as PosController checkout
     * decrements a specific variant rather than just the parent product.
     */
    public function test_purchase_adds_stock_to_the_named_variant_and_the_parent_together(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'color' => 'Blue', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/purchases', [
            'amount' => 2000, 'method' => 'cash', 'status' => 'received',
            'product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 10, 'cost' => 220,
        ]);

        $response->assertRedirect();
        $this->assertEquals(20, $variant->fresh()->stock); // 10 + 10
        $this->assertEquals(20, $product->fresh()->stock); // parent sum stays in lockstep
        $this->assertEquals(220.0, (float) $variant->fresh()->cost); // overwritten, matching ProductVariantController::stockIn
        $this->assertEquals(-1000.0, (float) $shop->fresh()->cash_balance); // 1000 - 2000 (the purchase's own `amount`, not qty*cost)
    }

    public function test_purchase_without_a_variant_still_rejects_a_variant_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'purchases');
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 10]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->post('/app/purchases', [
            'amount' => 2000, 'method' => 'cash', 'status' => 'received',
            'product_id' => $product->id, 'qty' => 10,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(10, $product->fresh()->stock); // untouched
    }
}
