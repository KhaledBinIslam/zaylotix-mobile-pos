<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\TableOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Permanent regression guard for the single most core action in the whole
 * app: picking a product/menu item at POS and having it actually reach a
 * paid sale with the right subtotal. This exact flow — "select a product ->
 * cart -> checkout" — has silently broken for real shops multiple times
 * across multiple sessions: each time a *different* new feature (VAT,
 * units, variants, stock modes, and most recently the restaurant_tables
 * feature flag being misused as a stand-in for "this shop's business type
 * is restaurant") reached into shared POS/checkout code and broke it for
 * some business type nobody happened to click-test afterward. See
 * Shop::isRestaurant()'s docblock for the fullest writeup of that last one.
 *
 * One test per business type Khaled actually runs today. If any of these
 * ever goes red, a real shop's cashier cannot sell anything and nothing
 * else matters until it's green again — this is deliberately not folded
 * into any other test file, so it can never be missed in a "some tests
 * failed" scroll of output.
 */
class CoreCartFlowTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function businessType(string $slug): BusinessType
    {
        return BusinessType::firstOrCreate(['slug' => $slug], [
            'label_bn' => $slug, 'label_en' => $slug, 'is_active' => true,
        ]);
    }

    /**
     * Shared assertion for every plain-POS (non-restaurant, non-clothing)
     * business type: land on the shared checkout screen (never redirected
     * away from it), add a product to the cart, checkout, and get back
     * exactly the right subtotal and stock decrement.
     */
    private function assertProductSellsThroughSharedPos(string $businessTypeSlug): void
    {
        $type = $this->businessType($businessTypeSlug);
        [$shop, $owner] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Test Product', 'cost' => 50, 'price' => 100, 'stock' => 20]);

        $this->actingAs($owner, 'web')->get('/app/pos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Pos/Index'));

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 300]],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale, "{$businessTypeSlug}: checkout did not create a sale — add-to-cart is broken for this business type");
        $this->assertEquals(300.0, (float) $sale->subtotal, "{$businessTypeSlug}: cart subtotal was wrong at checkout");
        $this->assertEquals(17, $product->fresh()->stock, "{$businessTypeSlug}: stock did not decrement correctly");
    }

    public function test_grocery_shop_can_add_a_product_to_cart_and_checkout(): void
    {
        $this->assertProductSellsThroughSharedPos('grocery');
    }

    public function test_pharmacy_shop_can_add_a_product_to_cart_and_checkout(): void
    {
        $this->assertProductSellsThroughSharedPos('pharmacy');
    }

    public function test_mobile_shop_can_add_a_product_to_cart_and_checkout(): void
    {
        $this->assertProductSellsThroughSharedPos('mobile');
    }

    public function test_general_shop_can_add_a_product_to_cart_and_checkout(): void
    {
        $this->assertProductSellsThroughSharedPos('general');
    }

    public function test_supershop_can_add_a_product_to_cart_and_checkout(): void
    {
        $this->assertProductSellsThroughSharedPos('supershop');
    }

    /** Clothing gets its own dedicated Pos component (see ClothingPosPageTest) — must still be the "sell" screen, never the restaurant tables flow, and a color/size variant must still reach a correct sale. */
    public function test_clothing_shop_can_add_a_variant_to_cart_and_checkout(): void
    {
        $type = $this->businessType('clothing');
        [$shop, $owner] = $this->createShopWithOwner(['business_type_id' => $type->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 20]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Blue', 'size' => 'M', 'stock' => 10]);

        $this->actingAs($owner, 'web')->get('/app/pos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Clothing/Pos'));

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 800]],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale, 'clothing: checkout did not create a sale — add-to-cart is broken for this business type');
        $this->assertEquals(800.0, (float) $sale->subtotal, 'clothing: cart subtotal was wrong at checkout');
        $this->assertEquals(8, $variant->fresh()->stock, 'clothing: variant stock did not decrement correctly');
    }

    /**
     * Restaurant's core flow is order-then-bill, not instant checkout (see
     * TableOrderController's docblock) — "add to cart" here means an item
     * reaching the open table order, and "checkout" means billing it into a
     * real Sale with the right subtotal. Also locks in the redirect the
     * whole rest of this file exists because of: a restaurant shop's "Sell"
     * must land on the Tables screen, never the plain product checkout.
     */
    public function test_restaurant_shop_can_add_a_menu_item_to_the_order_and_bill_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        // also makes the shop's business_type genuinely 'restaurant' — see
        // CreatesShops::grantFeature()'s docblock for why that matters here
        $this->grantFeature($shop, 'restaurant_tables');
        $product = Product::create([
            'shop_id' => $shop->id, 'name' => 'Biryani', 'cost' => 80, 'price' => 150, 'stock' => 0,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
        ]);
        $table = RestaurantTable::create(['shop_id' => $shop->id, 'name' => 'T1', 'status' => 'occupied']);
        $order = TableOrder::create(['shop_id' => $shop->id, 'restaurant_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()]);

        $this->actingAs($owner, 'web')->get('/app/pos')->assertRedirect(route('app.restaurant.tables.index'));

        $addResponse = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/items", [
            'product_id' => $product->id, 'qty' => 2,
        ]);
        $addResponse->assertOk();
        $this->assertEquals(2, $order->items()->sum('qty'), 'restaurant: item did not reach the open order — add-to-cart is broken for this business type');

        $billResponse = $this->actingAs($owner, 'web')->postJson("/app/restaurant/orders/{$order->id}/bill", [
            'payments' => [['method' => 'cash', 'amount' => 300]],
        ]);

        $billResponse->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale, 'restaurant: billing did not create a sale');
        $this->assertEquals(300.0, (float) $sale->subtotal, 'restaurant: cart subtotal was wrong at bill time');
    }
}
