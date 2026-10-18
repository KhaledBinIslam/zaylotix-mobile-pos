<?php

namespace Tests\Feature;

use App\Http\Controllers\App\ProductController;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Exercises the authenticated, same-tenant "happy path" for actions that
 * use policy checks ($this->authorize(...)) in the controller. These were
 * previously only covered by cross-tenant tests, where route-model-binding
 * already 404s before the policy check ever runs — which meant a missing
 * AuthorizesRequests trait on the base Controller (Laravel 11+ dropped it
 * from the default skeleton) went undetected: every same-tenant update/
 * delete/payment call was fatal-erroring with "Call to undefined method
 * authorize()". These tests fail loudly if that ever regresses.
 */
class ShopOperationsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_owner_can_update_their_own_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Old Name', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->put("/app/products/{$product->id}", [
            'name' => 'New Name',
            'cost' => 12,
            'price' => 25,
            'stock' => 8,
        ]);

        $response->assertRedirect();
        $this->assertSame('New Name', $product->fresh()->name);
        $this->assertEquals(25, (float) $product->fresh()->price);
    }

    public function test_owner_can_delete_their_own_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Gone Soon', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->delete("/app/products/{$product->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    /**
     * Regression test: stock-in used to overwrite Product.cost outright with
     * the new batch's cost, repricing every unit already on the shelf even
     * though only the newly received units cost that much. It must be a
     * weighted average of what's already there and what's coming in.
     */
    public function test_stock_in_averages_cost_instead_of_overwriting_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // 100 units on hand at cost 10 (value 1000)
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 15, 'stock' => 100]);

        // receive 50 more at cost 15 (value 750) -> weighted avg (1000+750)/150 = 11.67
        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 50,
            'cost' => 15,
        ]);

        $response->assertRedirect();
        $fresh = $product->fresh();
        $this->assertEquals(150, $fresh->stock);
        $this->assertEqualsWithDelta(11.67, (float) $fresh->cost, 0.01);
    }

    /**
     * Regression test: `StockIn::apply` used to check `! empty($cost)`,
     * which treats an explicit cost of 0 the same as "no cost given" (PHP's
     * empty(0.0) is true) — a legitimate free/zero-cost restock silently
     * left Product.cost unchanged instead of blending it toward 0.
     */
    public function test_stock_in_with_an_explicit_zero_cost_still_blends_into_the_average(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // 100 units on hand at cost 10 (value 1000)
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Free Sample', 'cost' => 10, 'price' => 15, 'stock' => 100]);

        // receive 100 more at cost 0 (a promo/free restock) -> weighted avg (1000+0)/200 = 5.00
        $response = $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/stock-in", [
            'qty' => 100,
            'cost' => 0,
        ]);

        $response->assertRedirect();
        $fresh = $product->fresh();
        $this->assertEquals(200, $fresh->stock);
        $this->assertEqualsWithDelta(5.00, (float) $fresh->cost, 0.01);
    }

    /**
     * Phase 1.1 — see docs/FIX_PLAN.md. Confirmed root cause of the Stock
     * page's reported 500: stock-in re-fetches the product under
     * lockForUpdate() with no null-guard, so a product soft-deleted between
     * route-binding and that re-fetch (double-submit, a second tab) threw
     * an uncaught TypeError instead of a friendly error. Route-model-binding
     * itself already excludes a soft-deleted product (confirmed by
     * ErrorHandlingTest's analogous case), so the only way to exercise the
     * actual race — deletion landing strictly between binding and the
     * controller's internal re-fetch — is to call the controller directly
     * with an already-resolved (not-yet-deleted-at-binding-time) $product.
     */
    public function test_stock_in_on_a_product_deleted_moments_earlier_is_a_friendly_error_not_a_500(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Vanishing', 'cost' => 10, 'price' => 15, 'stock' => 10]);
        $this->actingAs($owner, 'web');
        request()->merge(['qty' => 5]);
        $product->delete(); // the race: deleted after binding resolved it, before the controller's own re-fetch

        try {
            app(ProductController::class)->stockIn($product);
            $this->fail('Expected an HttpException (422) to be thrown.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * Phase 1.1 — see docs/FIX_PLAN.md. StockIn::apply() divides by
     * (stock + qty) when a new cost is given; pre-existing bad-data negative
     * stock that exactly offsets the incoming qty used to divide by zero
     * (silent NAN/INF on PHP floats) instead of just skipping the re-average.
     */
    public function test_stock_in_skips_the_cost_average_instead_of_dividing_by_zero(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Bad Data', 'cost' => 10, 'price' => 15, 'stock' => -5]);

        \App\Support\StockIn::apply($product, 5, 20);

        $fresh = $product->fresh();
        $this->assertEquals(0, $fresh->stock);
        $this->assertEquals(10, (float) $fresh->cost); // unchanged, not NAN
    }

    public function test_owner_can_collect_a_partial_payment_from_their_customer(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);

        $response = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments", [
            'amount' => 200,
        ]);

        $response->assertRedirect();
        $this->assertEquals(300, (float) $customer->fresh()->due);
        $this->assertEquals(1200, (float) $shop->fresh()->cash_balance); // seed default 1000 + 200
    }

    public function test_owner_can_mark_a_customer_fully_paid(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);

        $response = $this->actingAs($owner, 'web')->post("/app/customers/{$customer->id}/payments/full");

        $response->assertRedirect();
        $this->assertEquals(0, (float) $customer->fresh()->due);
    }

    /**
     * Regression test: PaymentController used to call
     * `$customer->lockForUpdate()` on an already-hydrated model instead of
     * `Customer::whereKey($id)->lockForUpdate()`. Since lockForUpdate() isn't
     * a real Model method, Eloquent forwarded it to a fresh query carrying
     * only the tenant scope (shop_id) — no primary-key WHERE clause — so the
     * subsequent decrement()/update() silently applied to every customer in
     * the shop instead of just the one being paid.
     */
    public function test_collecting_a_payment_does_not_touch_other_customers_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $paying = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);
        $bystander = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 200]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$paying->id}/payments", [
            'amount' => 200,
        ])->assertRedirect();

        $this->assertEquals(300, (float) $paying->fresh()->due);
        $this->assertEquals(200, (float) $bystander->fresh()->due);
    }

    public function test_marking_one_customer_fully_paid_does_not_zero_other_customers_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $paying = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 500]);
        $bystander = Customer::create(['shop_id' => $shop->id, 'name' => 'Rahim', 'due' => 200]);

        $this->actingAs($owner, 'web')->post("/app/customers/{$paying->id}/payments/full")
            ->assertRedirect();

        $this->assertEquals(0, (float) $paying->fresh()->due);
        $this->assertEquals(200, (float) $bystander->fresh()->due);
    }
}
