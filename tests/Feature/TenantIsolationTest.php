<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_queries_without_a_resolved_tenant_return_nothing(): void
    {
        [$shopA] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shopA->id, 'name' => 'A-Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        Tenancy::clear();

        // no tenant bound (e.g. an admin-guard request) -> scoped to nothing, never "everything"
        $this->assertSame(0, Product::count());
    }

    public function test_shop_user_only_sees_their_own_products(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        Product::create(['shop_id' => $shopA->id, 'name' => 'A-Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Product::create(['shop_id' => $shopB->id, 'name' => 'B-Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);
        Product::create(['shop_id' => $shopB->id, 'name' => 'B-Product-2', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($ownerA, 'web')->get('/app/stock');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'A-Product')
        );
    }

    public function test_shop_user_cannot_update_another_shops_product(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        $foreignProduct = Product::create(['shop_id' => $shopB->id, 'name' => 'B-Product', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $response = $this->actingAs($ownerA, 'web')->put("/app/products/{$foreignProduct->id}", [
            'name' => 'Hacked Name',
            'cost' => 1,
            'price' => 1,
            'stock' => 0,
        ]);

        // route-model-binding already tenant-scopes the lookup, so a cross-tenant
        // id simply doesn't resolve — this must never succeed with a 200.
        $response->assertStatus(404);

        $this->assertSame('B-Product', $foreignProduct->fresh()->name);
    }

    public function test_shop_user_cannot_see_another_shops_customers_or_dues(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        Customer::create(['shop_id' => $shopA->id, 'name' => 'A-Customer', 'due' => 100]);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'B-Customer', 'due' => 999]);

        $response = $this->actingAs($ownerA, 'web')->get('/app/customers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('customers', 1)
            ->where('customers.0.name', 'A-Customer')
        );
    }

    public function test_shop_user_cannot_see_another_shops_sales(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();

        Sale::create([
            'shop_id' => $shopA->id, 'invoice_no' => 'INV-A1', 'date' => now()->toDateString(),
            'time' => '10:00:00', 'subtotal' => 100, 'total' => 100, 'profit' => 10, 'payment_mode' => 'cash',
        ]);
        Sale::create([
            'shop_id' => $shopB->id, 'invoice_no' => 'INV-B1', 'date' => now()->toDateString(),
            'time' => '10:00:00', 'subtotal' => 500, 'total' => 500, 'profit' => 50, 'payment_mode' => 'cash',
        ]);

        $response = $this->actingAs($ownerA, 'web')->get('/app/home');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('todaySale', 100)
        );
    }

    public function test_new_records_are_auto_stamped_with_the_current_shop(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();

        $this->actingAs($ownerA, 'web')->post('/app/customers', [
            'name' => 'New Customer',
            'phone' => '01700000099',
        ])->assertRedirect();

        $customer = Customer::first();
        $this->assertSame($shopA->id, $customer->shop_id);
    }
}
