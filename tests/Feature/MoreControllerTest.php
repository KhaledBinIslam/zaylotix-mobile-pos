<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Regression coverage for docs/FIX_PLAN.md's "price/stock missing during
 * order/sale" fix — confirms MoreController::index()'s `products` prop
 * (feeding the Purchase/Damage/Return/Stock-count pickers) still carries
 * price and stock, the data the Purchase form's own product select was
 * missing from its template despite it always being present here.
 */
class MoreControllerTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_products_prop_carries_price_and_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'তেল', 'cost' => 120, 'price' => 150, 'stock' => 30]);

        $this->actingAs($owner, 'web')->get(route('app.more'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.0.price', fn ($v) => (float) $v === 150.0)
                ->where('products.0.stock', fn ($v) => (float) $v === 30.0));
    }
}
