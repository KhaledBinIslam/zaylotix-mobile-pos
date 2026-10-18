<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 2 — see docs/FIX_PLAN.md. Powers the POS checkout sheet's
 * search-by-name box, alongside the existing phone-based lookup().
 */
class CustomerSearchTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_searching_a_known_name_fragment_returns_matching_customers(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim Hossain', 'phone' => '01711111111', 'due' => 250]);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim Uddin', 'phone' => '01722222222', 'due' => 0]);

        $response = $this->actingAs($owner, 'web')->getJson('/app/customers/search?q=Karim');

        $response->assertOk();
        $results = $response->json('results');
        $this->assertCount(1, $results);
        $this->assertSame('Karim Hossain', $results[0]['name']);
        $this->assertEquals(250, $results[0]['due']);
    }

    public function test_search_requires_at_least_two_characters(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111']);

        $response = $this->actingAs($owner, 'web')->getJson('/app/customers/search?q=K');

        $response->assertOk()->assertJson(['results' => []]);
    }

    public function test_search_never_returns_another_shops_customer(): void
    {
        [, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Karim From Shop B', 'phone' => '01799999999']);

        $response = $this->actingAs($ownerA, 'web')->getJson('/app/customers/search?q=Karim');

        $response->assertOk()->assertJson(['results' => []]);
    }

    public function test_search_caps_results_at_eight(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        for ($i = 1; $i <= 10; $i++) {
            Customer::create(['shop_id' => $shop->id, 'name' => "Karim {$i}", 'phone' => "0171111111{$i}"]);
        }

        $response = $this->actingAs($owner, 'web')->getJson('/app/customers/search?q=Karim');

        $response->assertOk();
        $this->assertCount(8, $response->json('results'));
    }
}
