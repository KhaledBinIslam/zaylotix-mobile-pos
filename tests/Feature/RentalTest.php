<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Damage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Rental Management — sell some products, rent others. Renting never
 * decrements stock (the item is still shop property, just physically with
 * the customer); a payment (advance or at return) always goes through the
 * SAME Customer.due + Payment ledger PosController/PaymentController/
 * ReturnController already use, so a rental's own due always reconciles
 * exactly with the customer's real due ledger — Khaled's explicit ask.
 */
class RentalTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function rentableProduct(Shop $shop, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Sherwani', 'cost' => 800, 'price' => 3000, 'stock' => 5,
            'rental_mode' => Product::RENTAL_MODE_BOTH, 'rent_price' => 1500,
        ], $overrides));
    }

    public function test_renting_an_item_does_not_decrement_product_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'phone' => '01711112222', 'due' => 0]);

        $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
        ])->assertOk();

        $this->assertEquals(5, $product->fresh()->stock, 'stock must be unchanged — renting is not selling');
        $this->assertEquals(1500.0, (float) $customer->fresh()->due);
    }

    /**
     * Khaled's worked example line items (Sherwani ৳1500, Pagri ৳300, Mala
     * 2×৳200=৳400, Groom Shoes ৳500) — his own spec text then says "Total
     * ৳2,500", but those four lines actually sum to ৳2,700. The real
     * per-line math is what the controller must get right; this test
     * follows the correct sum (2700), not the spec's own arithmetic slip.
     */
    public function test_rental_with_advance_matches_khaleds_worked_example(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $this->grantFeature($shop, 'rentals');
        $sherwani = $this->rentableProduct($shop, ['name' => 'Sherwani', 'rent_price' => 1500]);
        $pagri = $this->rentableProduct($shop, ['name' => 'Pagri', 'rent_price' => 300]);
        $mala = $this->rentableProduct($shop, ['name' => 'Mala', 'rent_price' => 200]);
        $shoes = $this->rentableProduct($shop, ['name' => 'Groom Shoes', 'rent_price' => 500]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'phone' => '01711112222', 'due' => 0]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [
                ['product_id' => $sherwani->id, 'qty' => 1, 'rent_price' => 1500],
                ['product_id' => $pagri->id, 'qty' => 1, 'rent_price' => 300],
                ['product_id' => $mala->id, 'qty' => 2, 'rent_price' => 200],
                ['product_id' => $shoes->id, 'qty' => 1, 'rent_price' => 500],
            ],
            'advance_amount' => 1000,
            'advance_method' => 'cash',
        ]);

        $response->assertOk();
        $this->assertEquals(2700.0, (float) $response->json('rental.total_rent_amount'));
        $this->assertEquals(1700.0, (float) $customer->fresh()->due);
        $this->assertEquals(1000.0, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(1700.0, $response->json('rental.remaining_due'));
    }

    public function test_a_resubmitted_rental_create_with_the_same_client_uuid_is_not_duplicated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);
        $uuid = (string) Str::uuid();
        $payload = [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
            'client_uuid' => $uuid,
        ];

        $first = $this->actingAs($owner, 'web')->postJson('/app/rentals', $payload);
        $second = $this->actingAs($owner, 'web')->postJson('/app/rentals', $payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(1, Rental::count());
        $this->assertEquals(1500.0, (float) $customer->fresh()->due, 'due must be charged exactly once, not twice');
        $this->assertSame($first->json('rental.id'), $second->json('rental.id'));
    }

    public function test_returning_resalable_does_not_touch_stock_and_reduces_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 0]);
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);

        $create = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
        ]);
        $rentalId = $create->json('rental.id');
        $itemId = $create->json('rental.items.0.id');

        $return = $this->actingAs($owner, 'web')->postJson("/app/rentals/{$rentalId}/return", [
            'lines' => [['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'resalable']],
            'payment_amount' => 1500,
            'payment_method' => 'cash',
        ]);

        $return->assertOk();
        $this->assertEquals(5, $product->fresh()->stock, 'resalable return never touched stock in the first place');
        $this->assertEquals(0.0, (float) $customer->fresh()->due);
        $this->assertEquals(1500.0, (float) $shop->fresh()->cash_balance);
        $this->assertSame('returned', $return->json('rental.status'));
    }

    public function test_returning_damaged_decrements_stock_and_writes_a_damage_row(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop, ['stock' => 5]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);

        $create = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
        ]);
        $rentalId = $create->json('rental.id');
        $itemId = $create->json('rental.items.0.id');

        $return = $this->actingAs($owner, 'web')->postJson("/app/rentals/{$rentalId}/return", [
            'lines' => [['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'damaged', 'damage_charge' => 500]],
        ]);

        $return->assertOk();
        $this->assertEquals(4, $product->fresh()->stock, 'a genuine loss must decrement stock now — it was never decremented while rented');
        $this->assertEquals(1, Damage::where('rental_id', $rentalId)->count());
        // 1500 (rent) + 500 (damage charge), nothing paid yet
        $this->assertEquals(2000.0, (float) $customer->fresh()->due);
    }

    public function test_late_fee_increases_due_and_is_visible_on_the_rental(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);

        $create = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
            'advance_amount' => 1500,
        ]);
        $rentalId = $create->json('rental.id');
        $itemId = $create->json('rental.items.0.id');
        $this->assertEquals(0.0, (float) $customer->fresh()->due);

        $return = $this->actingAs($owner, 'web')->postJson("/app/rentals/{$rentalId}/return", [
            'lines' => [['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'resalable', 'late_fee' => 200]],
        ]);

        $return->assertOk();
        $this->assertEquals(200.0, (float) $customer->fresh()->due, 'late fee must be owed even though the item itself is fully settled');
    }

    public function test_cannot_return_more_than_the_items_outstanding_qty(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);

        $create = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
        ]);
        $rentalId = $create->json('rental.id');
        $itemId = $create->json('rental.items.0.id');

        $response = $this->actingAs($owner, 'web')->postJson("/app/rentals/{$rentalId}/return", [
            'lines' => [['rental_item_id' => $itemId, 'qty' => 2, 'condition' => 'resalable']],
        ]);

        $response->assertStatus(422);
    }

    public function test_a_resubmitted_return_with_no_payment_is_not_double_applied(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);

        $create = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
        ]);
        $rentalId = $create->json('rental.id');
        $itemId = $create->json('rental.items.0.id');
        $uuid = (string) Str::uuid();
        $payload = ['lines' => [['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'resalable', 'late_fee' => 200]], 'client_uuid' => $uuid];

        $first = $this->actingAs($owner, 'web')->postJson("/app/rentals/{$rentalId}/return", $payload);
        $second = $this->actingAs($owner, 'web')->postJson("/app/rentals/{$rentalId}/return", $payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertEquals(1700.0, (float) $customer->fresh()->due, '1500 rent + 200 late fee, charged exactly once');
        $this->assertSame(1, \App\Models\RentalReturn::where('rental_item_id', $itemId)->count());
    }

    public function test_per_variant_available_rented_damaged_lost_breakdown_is_accurate(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $this->grantFeature($shop, 'product_variants');
        $product = $this->rentableProduct($shop, ['stock' => 10, 'name' => 'Pagri']);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'Free', 'color' => 'Golden', 'stock' => 10]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 0]);

        $this->assertEquals(10, $variant->availableForRent());

        $create = $this->actingAs($owner, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id,
            'rental_date' => now()->toDateString(),
            'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 4, 'rent_price' => 300]],
        ]);
        $create->assertOk();

        $this->assertEquals(4, $variant->rentedQty());
        $this->assertEquals(6, $variant->fresh()->availableForRent(), '10 stock - 4 currently rented');

        $itemId = $create->json('rental.items.0.id');
        $this->actingAs($owner, 'web')->postJson('/app/rentals/'.$create->json('rental.id').'/return', [
            'lines' => [
                ['rental_item_id' => $itemId, 'qty' => 2, 'condition' => 'resalable'],
                ['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'damaged'],
                ['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'lost'],
            ],
        ])->assertOk();

        $this->assertEquals(0, $variant->rentedQty(), 'everything in this rental is now accounted for');
        $this->assertEquals(1, $variant->rentalDamagedQty());
        $this->assertEquals(1, $variant->rentalLostQty());
        $this->assertEquals(8, $variant->fresh()->stock, '10 - 1 damaged - 1 lost; the 2 resalable units were never removed from stock');
    }

    public function test_tenant_isolation_a_rental_from_another_shop_cannot_be_returned_against(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'rentals');
        $product = $this->rentableProduct($shopA);
        $customer = Customer::create(['shop_id' => $shopA->id, 'name' => 'Rahim', 'due' => 0]);
        $create = $this->actingAs($ownerA, 'web')->postJson('/app/rentals', [
            'customer_id' => $customer->id, 'rental_date' => now()->toDateString(), 'expected_return_date' => now()->addDays(3)->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1, 'rent_price' => 1500]],
        ]);
        $rentalId = $create->json('rental.id');
        $itemId = $create->json('rental.items.0.id');

        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'rentals');

        $response = $this->actingAs($ownerB, 'web')->postJson("/app/rentals/{$rentalId}/return", [
            'lines' => [['rental_item_id' => $itemId, 'qty' => 1, 'condition' => 'resalable']],
        ]);

        $response->assertStatus(404);
    }

    public function test_route_is_blocked_without_the_rentals_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // rentals feature NOT granted

        $this->actingAs($owner, 'web')->get('/app/rentals')->assertStatus(403);
    }

    public function test_rent_only_product_cannot_be_sold_at_pos(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'rentals');
        $product = $this->rentableProduct($shop, ['rental_mode' => Product::RENTAL_MODE_RENT_ONLY]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 3000]],
        ]);

        $response->assertStatus(422);
    }
}
