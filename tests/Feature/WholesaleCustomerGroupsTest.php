<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 4 "full Wholesale system", part B — wholesale customer groups (see
 * docs/FIX_PLAN.md's design note). A binary is_wholesale flag, scoped to
 * the toggle + its surfacing through the customer lookup/search endpoints
 * the POS frontend reads to auto-default sale_type — the actual wholesale
 * pricing math itself is already covered by WholesalePricingTest/
 * WholesaleTiersTest, since this flag never changes how a sale is priced
 * server-side (only what the POS UI defaults the sale_type toggle to).
 */
class WholesaleCustomerGroupsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_create_a_wholesale_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');

        $this->actingAs($owner, 'web')->post(route('app.customers.store'), [
            'name' => 'Karim Traders', 'phone' => '01811111111', 'is_wholesale' => true,
        ])->assertRedirect();

        $this->assertTrue((bool) Customer::where('phone', '01811111111')->first()->is_wholesale);
    }

    public function test_a_customer_defaults_to_not_wholesale(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->post(route('app.customers.store'), [
            'name' => 'Karim', 'phone' => '01811111111',
        ])->assertRedirect();

        $this->assertFalse((bool) Customer::where('phone', '01811111111')->first()->is_wholesale);
    }

    public function test_owner_can_toggle_an_existing_customers_wholesale_status(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111']);

        $this->actingAs($owner, 'web')->patch(route('app.customers.wholesale', $customer->id), ['is_wholesale' => true])->assertRedirect();
        $this->assertTrue((bool) $customer->refresh()->is_wholesale);

        $this->actingAs($owner, 'web')->patch(route('app.customers.wholesale', $customer->id), ['is_wholesale' => false])->assertRedirect();
        $this->assertFalse((bool) $customer->refresh()->is_wholesale);
    }

    public function test_wholesale_toggle_route_requires_the_wholesale_pricing_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111']);

        $this->actingAs($owner, 'web')->patch(route('app.customers.wholesale', $customer->id), ['is_wholesale' => true])->assertStatus(403);
    }

    public function test_owner_cannot_toggle_another_shops_customer(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'wholesale_pricing');
        [$shopB] = $this->createShopWithOwner();
        $otherCustomer = Customer::create(['shop_id' => $shopB->id, 'name' => 'Foreign Customer', 'phone' => '01899999999']);

        $this->actingAs($ownerA, 'web')->patch(route('app.customers.wholesale', $otherCustomer->id), ['is_wholesale' => true])->assertNotFound();
    }

    public function test_phone_lookup_reports_the_wholesale_flag(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01811111111', 'is_wholesale' => true]);

        $response = $this->actingAs($owner, 'web')->getJson(route('app.customers.lookup').'?phone=01811111111');

        $response->assertOk()->assertJson(['found' => true, 'is_wholesale' => true]);
    }

    public function test_name_search_reports_the_wholesale_flag(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'wholesale_pricing');
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim Traders', 'phone' => '01811111111', 'is_wholesale' => true]);

        $response = $this->actingAs($owner, 'web')->getJson(route('app.customers.search').'?q=Karim');

        $response->assertOk();
        $this->assertTrue($response->json('results.0.is_wholesale'));
    }
}
