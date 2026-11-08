<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park: "sell korte caile kono product na thakleo product sell korar
 * bebosta koro" — two independent requests, covered here:
 *
 * 1. An owner, or a cashier explicitly granted the 'oversell' permission,
 *    can confirm selling a line past its stock (stock then goes negative
 *    instead of blocking the sale); an ungranted cashier never can, even if
 *    their client sends the exact same flag — the server re-checks the
 *    permission itself, never trusts the payload (see
 *    PosController::checkout's own comment).
 * 2. A one-off item that isn't in the catalog yet can be created and sold
 *    in one motion from POS (PosController::quickItem), gated the same as
 *    creating any other product (perm:stock).
 */
class PosOversellAndQuickItemTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function cashier($shop, array $permissions): User
    {
        return User::create([
            'shop_id' => $shop->id,
            'name' => 'Cashier',
            'phone' => '018'.random_int(10000000, 99999999),
            'password' => 'password',
            'role' => 'staff',
            'lang' => 'bn',
            'permissions' => $permissions,
        ]);
    }

    public function test_a_cashier_cannot_oversell_even_when_the_client_sends_the_flag(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = $this->cashier($shop, ['pos']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 2]);

        $response = $this->actingAs($cashier, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5, 'allow_oversell' => true]],
            'payments' => [['method' => 'cash', 'amount' => 5450]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(2, $product->fresh()->stock);
        $this->assertNull(Sale::first());
    }

    public function test_a_cashier_granted_the_oversell_permission_can_oversell(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = $this->cashier($shop, ['pos', 'oversell']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 2]);

        $response = $this->actingAs($cashier, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5, 'allow_oversell' => true]],
            'payments' => [['method' => 'cash', 'amount' => 5450]],
        ]);

        $response->assertOk();
        $this->assertEquals(-3, $product->fresh()->stock);
        $this->assertNotNull(Sale::first());
    }

    public function test_the_owner_can_oversell_a_variant_past_its_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 1]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'XXL', 'color' => 'Grey', 'stock' => 1]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 3, 'allow_oversell' => true]],
            'payments' => [['method' => 'cash', 'amount' => 3270]],
        ]);

        $response->assertOk();
        $this->assertEquals(-2, $variant->fresh()->stock);
        $this->assertNotNull(Sale::first());
    }

    public function test_without_the_flag_an_owner_still_gets_blocked_by_insufficient_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 1]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 5]],
            'payments' => [['method' => 'cash', 'amount' => 5450]],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, $product->fresh()->stock);
    }

    public function test_quick_item_creates_a_real_product_and_sells_it_in_one_motion(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/quick-item', [
            'name' => 'One-off belt',
            'price' => 350,
            'qty' => 1,
        ]);

        $response->assertOk();
        $productId = $response->json('product.id');
        $product = Product::find($productId);
        $this->assertNotNull($product);
        $this->assertEquals(1, $product->stock);
        $this->assertEquals(350, $product->price);
        // cost is never sent to a client building the POS cart from this response
        $response->assertJsonMissingPath('product.cost');

        $sale = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 350]],
        ]);

        $sale->assertOk();
        $this->assertEquals(0, $product->fresh()->stock);
    }

    public function test_a_cashier_without_stock_permission_cannot_create_a_quick_item(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $cashier = $this->cashier($shop, ['pos']);

        $response = $this->actingAs($cashier, 'web')->postJson('/app/pos/quick-item', [
            'name' => 'One-off belt',
            'price' => 350,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Product::count());
    }
}
