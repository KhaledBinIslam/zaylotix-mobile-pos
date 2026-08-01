<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Every customer who owes money must stay fully visible on this list — it's
 * the shop owner's actionable collections list, not a browsable directory.
 * Only the already-settled half is capped, since that's reference-only.
 */
class CustomerIndexTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_every_customer_with_due_is_shown_even_past_the_cleared_cap(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        for ($i = 0; $i < 305; $i++) {
            Customer::create(['shop_id' => $shop->id, 'name' => "Due Customer {$i}", 'phone' => "017{$i}0000000", 'due' => 100 + $i]);
        }

        $response = $this->actingAs($owner, 'web')->get('/app/customers');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('customers', 305));
    }

    public function test_settled_customers_are_capped_at_300(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        for ($i = 0; $i < 310; $i++) {
            Customer::create(['shop_id' => $shop->id, 'name' => "Settled Customer {$i}", 'phone' => "018{$i}0000000", 'due' => 0]);
        }

        $response = $this->actingAs($owner, 'web')->get('/app/customers');

        $response->assertOk()->assertInertia(fn ($page) => $page->has('customers', 300));
    }
}
