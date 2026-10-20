<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 3 feature 8 — "ক্রেডিট লিমিট" (see docs/FIX_PLAN.md's design note).
 * Reuses the existing customers.due/checkout-credit flow entirely — this
 * just adds a ceiling checkout is checked against. A null limit (every
 * shop's default, even with the feature on) means unlimited, identical
 * to today's behavior.
 */
class CreditLimitTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function checkoutOnCredit($owner, Customer $customer, Product $product, float $paidNow): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'customer_phone' => $customer->phone,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => $paidNow > 0 ? [['method' => 'cash', 'amount' => $paidNow]] : [],
        ]);
    }

    public function test_checkout_is_blocked_once_a_customers_own_limit_would_be_exceeded(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'credit_limit');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 400, 'credit_limit' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        // due 400 + 200 new due = 600, over the 500 limit
        $this->checkoutOnCredit($owner, $customer, $product, 0)->assertStatus(422);
        $this->assertEquals(400.0, (float) $customer->refresh()->due);
    }

    public function test_checkout_succeeds_when_still_under_the_customers_own_limit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'credit_limit');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 100, 'credit_limit' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        $this->checkoutOnCredit($owner, $customer, $product, 0)->assertOk();
        $this->assertEquals(300.0, (float) $customer->refresh()->due);
    }

    public function test_shop_wide_default_applies_when_customer_has_no_override(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['default_credit_limit' => 500]);
        $this->grantFeature($shop, 'credit_limit');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 400]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        $this->checkoutOnCredit($owner, $customer, $product, 0)->assertStatus(422);
    }

    public function test_customers_own_override_wins_over_the_shop_wide_default(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['default_credit_limit' => 300]);
        $this->grantFeature($shop, 'credit_limit');
        // shop default (300) would block this, but the customer's own override (1000) allows it
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 400, 'credit_limit' => 1000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        $this->checkoutOnCredit($owner, $customer, $product, 0)->assertOk();
    }

    public function test_no_block_when_no_limit_is_set_anywhere(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'credit_limit');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 100000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        $this->checkoutOnCredit($owner, $customer, $product, 0)->assertOk();
    }

    public function test_no_block_without_the_feature_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['default_credit_limit' => 500]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 400]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        $this->checkoutOnCredit($owner, $customer, $product, 0)->assertOk();
    }

    public function test_a_partial_cash_payment_still_counts_the_remaining_due_against_the_limit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'credit_limit');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'due' => 400, 'credit_limit' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 200, 'stock' => 10]);

        // paying 50 of the 200 leaves 150 due -> 400 + 150 = 550, still over 500
        $this->checkoutOnCredit($owner, $customer, $product, 50)->assertStatus(422);
    }

    public function test_owner_can_set_the_shop_wide_default(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'credit_limit');

        $this->actingAs($owner, 'web')->patch(route('app.settings.creditLimit'), ['default_credit_limit' => 750])->assertRedirect();

        $this->assertEquals(750.0, (float) $shop->refresh()->default_credit_limit);
    }

    public function test_owner_can_set_a_customers_own_credit_limit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'credit_limit');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111']);

        $this->actingAs($owner, 'web')->patch(route('app.customers.creditLimit', $customer->id), ['credit_limit' => 1200])->assertRedirect();

        $this->assertEquals(1200.0, (float) $customer->refresh()->credit_limit);
    }

    public function test_owner_cannot_set_another_shops_customer_credit_limit(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'credit_limit');
        [$shopB] = $this->createShopWithOwner();
        $otherCustomer = Customer::create(['shop_id' => $shopB->id, 'name' => 'Foreign Customer', 'phone' => '01899999999']);

        // Customer uses BusinessScope (not an exact-shop TenantScope — see
        // Customer's own docblock), so route-model-binding's query already
        // filters out a different, unrelated business's customer entirely
        // — this 404s rather than reaching the policy's 403.
        $this->actingAs($ownerA, 'web')->patch(route('app.customers.creditLimit', $otherCustomer->id), ['credit_limit' => 1000])->assertNotFound();
    }
}
