<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 3 feature 6 — "রেফারেল" (see docs/FIX_PLAN.md's design note). The
 * reward pays into the existing loyalty_points balance and fires on the
 * referred customer's FIRST real sale, never at referral-creation time —
 * crediting at creation would be trivially abusable (fake customers
 * referring each other for free points with no purchase at all).
 */
class CustomerReferralTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function sellOne(Customer $customer, $owner, $product): void
    {
        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'customer_phone' => $customer->phone,
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => (float) $product->price]],
        ])->assertOk();
    }

    public function test_referrer_is_rewarded_on_referred_customers_first_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['referral_bonus_points' => 50]);
        $this->grantFeature($shop, 'customer_referrals');
        $this->grantFeature($shop, 'loyalty_points');

        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01811111111']);
        $referred = Customer::create(['shop_id' => $shop->id, 'name' => 'Referred', 'phone' => '01822222222', 'referred_by_customer_id' => $referrer->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->sellOne($referred, $owner, $product);

        $referrer->refresh();
        $referred->refresh();
        $this->assertEquals(50, $referrer->loyalty_points);
        $this->assertNotNull($referred->referral_rewarded_at);
    }

    public function test_referrer_is_not_rewarded_twice_on_a_second_sale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['referral_bonus_points' => 50]);
        $this->grantFeature($shop, 'customer_referrals');
        $this->grantFeature($shop, 'loyalty_points');

        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01811111111']);
        $referred = Customer::create(['shop_id' => $shop->id, 'name' => 'Referred', 'phone' => '01822222222', 'referred_by_customer_id' => $referrer->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->sellOne($referred, $owner, $product);
        $this->sellOne($referred, $owner, $product);

        $referrer->refresh();
        $this->assertEquals(50, $referrer->loyalty_points);
    }

    public function test_no_reward_without_a_referral_on_file(): void
    {
        // loyalty_points deliberately NOT granted here — this test isolates
        // "no referral on file means no reward", not normal point-earning
        // (which is gated on loyalty_points and unrelated to referrals).
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'customer_referrals');

        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Plain Customer', 'phone' => '01833333333']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->sellOne($customer, $owner, $product);

        $customer->refresh();
        $this->assertEquals(0, $customer->loyalty_points);
        $this->assertNull($customer->referral_rewarded_at);
    }

    public function test_no_reward_when_customer_referrals_feature_is_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['referral_bonus_points' => 50]);
        $this->grantFeature($shop, 'loyalty_points');

        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01811111111']);
        $referred = Customer::create(['shop_id' => $shop->id, 'name' => 'Referred', 'phone' => '01822222222', 'referred_by_customer_id' => $referrer->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->sellOne($referred, $owner, $product);

        $referrer->refresh();
        $this->assertEquals(0, $referrer->loyalty_points);
    }

    public function test_no_reward_when_loyalty_points_feature_is_off(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['referral_bonus_points' => 50]);
        $this->grantFeature($shop, 'customer_referrals');

        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01811111111']);
        $referred = Customer::create(['shop_id' => $shop->id, 'name' => 'Referred', 'phone' => '01822222222', 'referred_by_customer_id' => $referrer->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->sellOne($referred, $owner, $product);

        $referrer->refresh();
        $this->assertEquals(0, $referrer->loyalty_points);
    }

    public function test_referred_by_customer_id_cannot_point_at_another_shops_customer(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $otherShopCustomer = Customer::create(['shop_id' => $shopB->id, 'name' => 'Foreign Customer', 'phone' => '01899999999']);

        $response = $this->actingAs($ownerA, 'web')->post(route('app.customers.store'), [
            'name' => 'New Customer',
            'phone' => '01844444444',
            'referred_by_customer_id' => $otherShopCustomer->id,
        ]);

        $response->assertSessionHasErrors('referred_by_customer_id');
        $this->assertDatabaseMissing('customers', ['phone' => '01844444444']);
    }

    public function test_store_records_the_referral_on_the_new_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $referrer = Customer::create(['shop_id' => $shop->id, 'name' => 'Referrer', 'phone' => '01811111111']);

        $this->actingAs($owner, 'web')->post(route('app.customers.store'), [
            'name' => 'New Customer',
            'phone' => '01844444444',
            'referred_by_customer_id' => $referrer->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'phone' => '01844444444',
            'referred_by_customer_id' => $referrer->id,
        ]);
    }
}
