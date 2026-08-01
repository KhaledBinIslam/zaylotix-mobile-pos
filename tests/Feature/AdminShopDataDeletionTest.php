<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AdminShopDataDeletionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Admin', 'email' => 'delete-admin-'.uniqid().'@test.com', 'password' => 'password']);
    }

    public function test_admin_can_permanently_delete_a_shop_and_every_related_row(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 1, 'price' => 2, 'stock' => 5]);
        Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'due' => 100]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/shops/{$shop->id}", ['confirm_name' => $shop->name]);

        $response->assertRedirect(route('admin.shops.index'));
        $this->assertDatabaseMissing('shops', ['id' => $shop->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    }

    /**
     * Regression test: notifications and personal_access_tokens are
     * polymorphic (notifiable_type/tokenable_type) with no FK to users, so
     * the cascade-delete on users.shop_id never touched them — they used to
     * survive as orphaned rows referencing a user id that no longer exists.
     */
    public function test_shop_deletion_also_removes_the_owners_notifications_and_api_tokens(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $owner->notify(new \App\Notifications\AdminMessage('bye'));
        $owner->createToken('test-device');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $owner->id]);

        $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/shops/{$shop->id}", ['confirm_name' => $shop->name])
            ->assertRedirect();

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $owner->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $owner->id]);
    }

    public function test_shop_deletion_is_refused_if_the_typed_name_does_not_match(): void
    {
        [$shop] = $this->createShopWithOwner();

        $response = $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/shops/{$shop->id}", ['confirm_name' => 'wrong name']);

        $response->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('shops', ['id' => $shop->id]);
    }

    public function test_admin_can_delete_a_single_product_without_touching_the_rest_of_the_shop(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 1, 'price' => 2, 'stock' => 5]);
        $other = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/shops/{$shop->id}/products/{$product->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('products', ['id' => $other->id, 'deleted_at' => null]);
    }

    public function test_admin_cannot_delete_a_product_belonging_to_a_different_shop(): void
    {
        [$shopA] = $this->createShopWithOwner();
        [$shopB] = $this->createShopWithOwner();
        $productB = Product::create(['shop_id' => $shopB->id, 'name' => 'Oil', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/shops/{$shopA->id}/products/{$productB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $productB->id, 'deleted_at' => null]);
    }

    public function test_deleting_a_sale_reverses_stock_balance_and_customer_due(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 150, 'stock' => 5]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Karim', 'phone' => '01711111111', 'due' => 300, 'total_spent' => 300, 'visits' => 1]);

        $sale = Sale::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-1',
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 300, 'discount' => 0, 'vat' => 0, 'total' => 300, 'profit' => 100, 'payment_mode' => 'credit',
        ]);
        SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'unit_factor' => 1, 'qty' => 2, 'price' => 150, 'cost' => 100,
        ]);
        $product->decrement('stock', 2); // stock now 3, matching what the sale would have done

        $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/shops/{$shop->id}/sales/{$sale->id}")
            ->assertRedirect();

        $this->assertEquals(5, $product->fresh()->stock); // 3 + 2 given back
        $this->assertEquals(0.0, (float) $customer->fresh()->due); // 300 - 300
        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertDatabaseMissing('sale_items', ['sale_id' => $sale->id]);
    }

    public function test_shop_owner_cannot_reach_any_admin_delete_route(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $this->actingAs($owner, 'web')
            ->delete("/admin/shops/{$shop->id}/products/{$product->id}")
            ->assertRedirect(route('admin.login'));

        $this->actingAs($owner, 'web')
            ->delete("/admin/shops/{$shop->id}", ['confirm_name' => $shop->name])
            ->assertRedirect(route('admin.login'));
    }
}
