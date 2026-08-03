<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A clothing-business-type shop gets a dedicated category -> product ->
 * color/size POS page (App/Clothing/Pos) instead of the shared App/Pos/Index
 * — every other business type must keep seeing the shared screen unchanged.
 * Both render off the exact same PosController::index()/checkout() data and
 * routes, so this only locks in which Inertia component is chosen.
 */
class ClothingPosPageTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_clothing_shop_gets_the_dedicated_clothing_pos_page(): void
    {
        $clothing = BusinessType::create(['slug' => 'clothing', 'label_bn' => 'কাপড়ের দোকান', 'label_en' => 'Clothing', 'is_active' => true]);
        [$shop, $owner] = $this->createShopWithOwner(['business_type_id' => $clothing->id]);

        $response = $this->actingAs($owner, 'web')->get('/app/pos');

        $response->assertOk()->assertInertia(fn ($page) => $page->component('App/Clothing/Pos'));
    }

    public function test_a_grocery_shop_still_gets_the_shared_pos_page(): void
    {
        $grocery = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'মুদি দোকান', 'label_en' => 'Grocery', 'is_active' => true]);
        [$shop, $owner] = $this->createShopWithOwner(['business_type_id' => $grocery->id]);

        $response = $this->actingAs($owner, 'web')->get('/app/pos');

        $response->assertOk()->assertInertia(fn ($page) => $page->component('App/Pos/Index'));
    }

    public function test_a_shop_with_no_business_type_still_gets_the_shared_pos_page(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // business_type_id left null

        $response = $this->actingAs($owner, 'web')->get('/app/pos');

        $response->assertOk()->assertInertia(fn ($page) => $page->component('App/Pos/Index'));
    }

    public function test_checkout_from_the_clothing_page_decrements_the_chosen_variant_exactly_like_the_shared_page(): void
    {
        $clothing = BusinessType::create(['slug' => 'clothing', 'label_bn' => 'কাপড়ের দোকান', 'label_en' => 'Clothing', 'is_active' => true]);
        [$shop, $owner] = $this->createShopWithOwner(['business_type_id' => $clothing->id]);
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 200, 'price' => 400, 'stock' => 20]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Blue', 'size' => 'M', 'stock' => 10]);

        $response = $this->actingAs($owner, 'web')->postJson('/app/pos/checkout', [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 800]],
        ]);

        $response->assertOk();
        $this->assertEquals(8, $variant->fresh()->stock);
        $this->assertEquals(18, $product->fresh()->stock);
    }
}
