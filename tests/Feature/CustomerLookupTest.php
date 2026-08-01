<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Powers the POS checkout sheet's "auto-fill name for a returning customer" behavior. */
class CustomerLookupTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_looking_up_a_known_phone_returns_the_customers_name_and_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 250]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/customers/lookup?phone=01711111111');

        $response->assertOk()->assertJson(['found' => true, 'name' => 'Karim', 'due' => 250]);
    }

    public function test_looking_up_an_unknown_phone_returns_not_found(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->getJson('/app/customers/lookup?phone=01799999999');

        $response->assertOk()->assertJson(['found' => false]);
    }

    public function test_lookup_never_returns_another_shops_customer(): void
    {
        [, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Other Shop Customer', 'phone' => '01722222222', 'due' => 50]);

        $response = $this->actingAs($ownerA, 'web')->getJson('/app/customers/lookup?phone=01722222222');

        $response->assertOk()->assertJson(['found' => false]);
    }
}
