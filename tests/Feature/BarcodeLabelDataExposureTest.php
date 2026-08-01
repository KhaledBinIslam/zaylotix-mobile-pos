<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * `barcode_labels` can be granted to a cashier independently of `stock` —
 * this page must not ship cost/margin data to a client who may not be
 * allowed to see it, regardless of what the template chooses to render.
 */
class BarcodeLabelDataExposureTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_barcode_label_products_do_not_include_cost(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        Product::create(['shop_id' => $shop->id, 'name' => 'Soap', 'cost' => 55, 'price' => 90, 'stock' => 10, 'barcode' => '1234567890']);

        $response = $this->actingAs($owner, 'web')->get('/app/barcode-labels');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->missing('products.0.cost')
        );
    }
}
