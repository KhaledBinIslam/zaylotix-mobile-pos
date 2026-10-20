<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 3 feature 7 — "ডিজিটাল ক্যাটালগ লিঙ্ক" (see docs/FIX_PLAN.md's design
 * note). A public, no-login page (CatalogController) listing only the
 * products the owner opted in (show_in_catalog), never exposing cost
 * price or any other money-in figure.
 */
class DigitalCatalogTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_public_catalog_lists_only_products_marked_visible(): void
    {
        [$shop] = $this->createShopWithOwner(['catalog_slug' => 'test-shop-abc123']);
        $this->grantFeature($shop, 'digital_catalog');
        Product::create(['shop_id' => $shop->id, 'name' => 'Shown Item', 'cost' => 50, 'price' => 100, 'stock' => 10, 'show_in_catalog' => true]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Hidden Item', 'cost' => 50, 'price' => 100, 'stock' => 10, 'show_in_catalog' => false]);

        $this->get('/catalog/test-shop-abc123')->assertInertia(fn ($page) => $page
            ->has('products', 1)
            ->where('products.0.name', 'Shown Item')
        );
    }

    public function test_public_catalog_never_exposes_cost_price(): void
    {
        [$shop] = $this->createShopWithOwner(['catalog_slug' => 'test-shop-xyz789']);
        $this->grantFeature($shop, 'digital_catalog');
        Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10, 'show_in_catalog' => true]);

        $this->get('/catalog/test-shop-xyz789')->assertInertia(fn ($page) => $page->missing('products.0.cost'));
    }

    public function test_catalog_shows_discount_price_and_marks_out_of_stock(): void
    {
        [$shop] = $this->createShopWithOwner(['catalog_slug' => 'test-shop-disc1']);
        $this->grantFeature($shop, 'digital_catalog');
        Product::create(['shop_id' => $shop->id, 'name' => 'A On Sale', 'cost' => 50, 'price' => 100, 'discount_price' => 70, 'stock' => 10, 'show_in_catalog' => true]);
        Product::create(['shop_id' => $shop->id, 'name' => 'B Out Of Stock', 'cost' => 50, 'price' => 100, 'stock' => 0, 'show_in_catalog' => true]);

        // ordered by name, so "A On Sale" is index 0 and "B Out Of Stock" is index 1
        $this->get('/catalog/test-shop-disc1')->assertInertia(fn ($page) => $page
            ->where('products.0.price', 70)
            ->where('products.0.original_price', 100)
            ->where('products.1.in_stock', false)
        );
    }

    public function test_catalog_404s_without_the_feature_granted(): void
    {
        [$shop] = $this->createShopWithOwner(['catalog_slug' => 'test-shop-nofeat']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10, 'show_in_catalog' => true]);

        $this->get('/catalog/test-shop-nofeat')->assertNotFound();
    }

    public function test_catalog_404s_for_an_inactive_shop(): void
    {
        [$shop] = $this->createShopWithOwner(['catalog_slug' => 'test-shop-inactive', 'status' => 'inactive']);
        $this->grantFeature($shop, 'digital_catalog');

        $this->get('/catalog/test-shop-inactive')->assertNotFound();
    }

    public function test_catalog_404s_for_an_unknown_slug(): void
    {
        $this->get('/catalog/no-such-shop')->assertNotFound();
    }

    public function test_opening_catalog_settings_lazily_assigns_a_slug(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'digital_catalog');
        $this->assertNull($shop->catalog_slug);

        $this->actingAs($owner, 'web')->get(route('app.catalog.index'))->assertOk();

        $this->assertNotNull($shop->refresh()->catalog_slug);
    }

    public function test_owner_can_toggle_a_products_catalog_visibility(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'digital_catalog');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->patch(route('app.products.catalogVisibility', $product->id), ['show_in_catalog' => true])->assertRedirect();

        $this->assertTrue($product->refresh()->show_in_catalog);
    }

    public function test_owner_cannot_toggle_another_shops_product_visibility(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'digital_catalog');
        [$shopB] = $this->createShopWithOwner();
        $otherProduct = Product::create(['shop_id' => $shopB->id, 'name' => 'Foreign Item', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        // route-model-binding already tenant-scopes the lookup, so a
        // cross-tenant id simply doesn't resolve — see TenantIsolationTest's
        // identical note on the plain product-update route.
        $this->actingAs($ownerA, 'web')->patch(route('app.products.catalogVisibility', $otherProduct->id), ['show_in_catalog' => true])->assertNotFound();
    }
}
