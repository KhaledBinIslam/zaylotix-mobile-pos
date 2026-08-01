<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Damage;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Covers the "system should explain what went wrong, never just sit there"
 * audit: every error a shop user can hit — missing record, an over-entered
 * amount, a duplicate customer, writing off more stock than exists — must
 * come back as something the UI can actually show, not a raw/blank crash.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_missing_page_renders_the_branded_error_page_not_a_raw_404(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->get('/app/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));
    }

    public function test_deleting_an_already_deleted_product_renders_the_branded_error_page(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->delete('/app/products/999999');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));
    }

    public function test_collecting_more_than_the_due_amount_is_rejected_with_a_field_error(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 300]);

        $response = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", [
            'amount' => 500,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertEquals(300, (float) $customer->fresh()->due, 'due must be untouched, not silently capped');
    }

    public function test_adding_a_customer_with_an_existing_phone_is_rejected_not_duplicated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01812111222', 'due' => 0]);

        $response = $this->actingAs($owner, 'web')->post('/app/customers', [
            'name' => 'Karim Again',
            'phone' => '01812111222',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(1, Customer::where('phone', '01812111222')->count());
    }

    public function test_writing_off_more_damage_than_stock_shows_a_field_error_not_a_crash(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'damages');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post('/app/damages', [
            'product_id' => $product->id,
            'qty' => 999,
        ]);

        $response->assertSessionHasErrors('qty');
        $this->assertEquals(5, $product->fresh()->stock);
        $this->assertSame(0, Damage::count());
    }
}
