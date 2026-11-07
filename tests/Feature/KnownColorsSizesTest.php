<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled live: "color size eshb add kora aro easy koro ekbar korle pore
 * jate use kora jai" — every colour/size ever typed on a variant in this
 * shop is offered as a suggestion (datalist) the next time one is typed,
 * instead of retyping it from scratch (and risking the exact typo that
 * caused INV-1001's twin-variant bug).
 */
class KnownColorsSizesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_stock_page_lists_every_distinct_colour_and_size_used(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        \App\Support\Tenancy::set($shop->id);

        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'color' => 'Navy Blue', 'size' => 'M', 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'color' => 'Navy Blue', 'size' => 'L', 'stock' => 0]); // duplicate colour, must appear once
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'color' => 'Grey', 'size' => null, 'stock' => 0]);

        $this->actingAs($owner, 'web')->get('/app/stock')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('knownColors', ['Grey', 'Navy Blue'])
                ->where('knownSizes', ['L', 'M'])
            );
    }
}
