<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * No backend code changed for this fix (see docs/FIX_PLAN.md's pending-
 * sales-sheet fix) — the fix is entirely in how the client edits a stuck
 * offline entry and resubmits it. These tests prove that edit-then-
 * resubmit flow actually works against the real checkout endpoint, for
 * every case the sheet's new generic fix panel offers.
 */
class OfflineSyncEditRetryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_adding_a_customer_phone_to_a_due_only_bill_and_resubmitting_succeeds(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'জুতা', 'cost' => 400, 'price' => 500, 'stock' => 10]);

        // first attempt — no customer_phone, no payments — rejected exactly
        // like the screenshot, never creates a Sale row
        $first = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => '11111111-1111-1111-1111-111111111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [],
            'offline_replay' => true,
        ]);
        $first->assertStatus(422)->assertJsonFragment(['message' => 'বাকি রাখতে হলে কাস্টমারের ফোন নম্বর দিন।']);
        $this->assertSame(0, Sale::count());

        // same client_uuid, now with a customer phone added — exactly what
        // the sheet's "ঠিক করুন" panel does
        $second = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => '11111111-1111-1111-1111-111111111111',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [],
            'customer_phone' => '01700000000',
            'customer_name' => 'করিম',
            'offline_replay' => true,
        ]);
        $second->assertOk();
        $this->assertSame(1, Sale::count());
        $this->assertEquals(500, Sale::first()->customer->due);
    }

    public function test_marking_a_due_only_bill_fully_paid_in_cash_and_resubmitting_succeeds_with_zero_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'জুতা', 'cost' => 400, 'price' => 500, 'stock' => 10]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => '22222222-2222-2222-2222-222222222222',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [],
            'offline_replay' => true,
        ])->assertStatus(422);

        // the sheet's "✅ সম্পূর্ণ নগদে নেওয়া হয়েছে" quick action
        $resubmit = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => '22222222-2222-2222-2222-222222222222',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
            'offline_replay' => true,
        ]);

        $resubmit->assertOk();
        $this->assertSame(1, Sale::count());
        $this->assertEquals(0, Sale::first()->total - 500);
    }

    public function test_reducing_an_items_quantity_below_the_available_stock_and_resubmitting_succeeds(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'জুতা', 'cost' => 400, 'price' => 500, 'stock' => 2]);

        // first attempt wants more than available
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => '33333333-3333-3333-3333-333333333333',
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 2500]],
            'offline_replay' => true,
        ])->assertStatus(422);
        $this->assertSame(0, Sale::count());
        $this->assertEquals(2, $product->refresh()->stock); // never partially decremented

        // the sheet's per-item qty edit, reduced to what's actually in stock
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'client_uuid' => '33333333-3333-3333-3333-333333333333',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 1000]],
            'offline_replay' => true,
        ])->assertOk();

        $this->assertSame(1, Sale::count());
        $this->assertEquals(0, $product->refresh()->stock);
    }

    public function test_the_same_client_uuid_resubmitted_twice_after_a_successful_edit_still_creates_exactly_one_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'জুতা', 'cost' => 400, 'price' => 500, 'stock' => 10]);

        $payload = [
            'client_uuid' => '44444444-4444-4444-4444-444444444444',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 500]],
            'offline_replay' => true,
        ];

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload)->assertOk();
        // a manual double-tap of "সব সিঙ্ক করুন" (or trySync racing a
        // manual retry) replays the exact same, already-synced payload —
        // idempotency must still hold, same as any other offline-sync path
        $replay = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', $payload);

        $replay->assertOk();
        $this->assertSame(1, Sale::count());
        $this->assertEquals(9, $product->refresh()->stock); // decremented exactly once
    }
}
