<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN: "due te parties or kono person k sell korte caile select er
 * option dia dao" — POS's own Party picker. A party (feature: parties) is
 * a Customer row (see PartyController's own docblock) but commonly carries
 * no phone number, which the existing phone-keyed due-sale flow could
 * never resolve. Checkout now also accepts an explicit customer_id, and
 * the due-needs-a-way-to-identify-them gate accepts either.
 */
class PosPartySaleTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithPartyAndProduct(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'parties');
        \App\Support\Tenancy::set($shop->id);

        $party = Customer::create(['shop_id' => $shop->id, 'name' => 'Bashundhara', 'party_type' => 'company', 'company_name' => 'Bashundhara Shipping Ltd', 'due' => 500]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 10]);

        return [$shop, $owner, $party, $product];
    }

    public function test_checkout_attaches_a_phoneless_party_by_id(): void
    {
        [, $owner, $party, $product] = $this->shopWithPartyAndProduct();

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_id' => $party->id,
            'payments' => [],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertSame($party->id, $sale->customer_id);
        $this->assertEquals(1590, (float) $party->fresh()->due); // 500 + 1090, all on credit
    }

    public function test_due_sale_is_refused_without_either_a_phone_or_a_party_id(): void
    {
        [, $owner, , $product] = $this->shopWithPartyAndProduct();

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [],
        ]);

        $response->assertStatus(422);
        $this->assertNull(Sale::first());
    }

    public function test_customer_id_wins_over_a_mismatched_phone(): void
    {
        [, $owner, $party, $product] = $this->shopWithPartyAndProduct();
        Customer::create(['name' => 'Someone Else', 'phone' => '01700000009', 'due' => 0]);

        $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_id' => $party->id,
            'customer_phone' => '01700000009',
            'payments' => [],
        ])->assertOk();

        $sale = Sale::latest('id')->first();
        $this->assertSame($party->id, $sale->customer_id);
    }

    public function test_customer_id_from_another_shop_is_not_attached(): void
    {
        [, $owner, , $product] = $this->shopWithPartyAndProduct();
        [$otherShop] = $this->createShopWithOwner();
        $foreignParty = Customer::create(['shop_id' => $otherShop->id, 'name' => 'Not Yours', 'due' => 0]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'customer_id' => $foreignParty->id,
            'payments' => [],
        ]);

        // no resolvable customer, same as an unmatched phone — refused for the same reason
        $response->assertStatus(422);
        $this->assertNull(Sale::first());
    }

    public function test_party_search_lists_only_party_rows_and_matches_company_name(): void
    {
        [, $owner, $party] = $this->shopWithPartyAndProduct();
        Customer::create(['name' => 'Regular Joe', 'phone' => '01700000002', 'due' => 0]); // not a party

        $byEmpty = $this->actingAs($owner, 'web')->getJson('/app/customers/search?party_only=1')->json('results');
        $this->assertCount(1, $byEmpty);
        $this->assertSame($party->id, $byEmpty[0]['id']);

        $byCompany = $this->actingAs($owner, 'web')->getJson('/app/customers/search?party_only=1&q=Shipping')->json('results');
        $this->assertCount(1, $byCompany);

        $byMiss = $this->actingAs($owner, 'web')->getJson('/app/customers/search?party_only=1&q=zzzznotfound')->json('results');
        $this->assertCount(0, $byMiss);
    }
}
