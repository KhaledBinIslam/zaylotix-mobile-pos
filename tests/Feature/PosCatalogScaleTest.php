<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Scale follow-up (docs/SCALING_GUIDE.md item #4, docs/FIX_PLAN.md's design
 * note) — PosController::index() stops sending the whole catalog once a
 * shop's product count passes config('pos.full_catalog_limit'), switching
 * to server-side search() + a background catalogSyncChunk() sync instead.
 * The single most important test here is the first one: a normal shop's
 * page must stay byte-identical to the app's original behavior.
 */
class PosCatalogScaleTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_normal_shop_gets_the_full_catalog_in_full_mode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 80, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->get('/app/pos')->assertInertia(fn ($page) => $page
            ->where('catalogMode', 'full')
            ->where('productCount', 2)
            ->has('products', 2)
        );
    }

    public function test_a_shop_over_the_configured_limit_gets_search_mode_with_an_empty_catalog(): void
    {
        config(['pos.full_catalog_limit' => 2]);
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 80, 'price' => 100, 'stock' => 10]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Sugar', 'cost' => 60, 'price' => 90, 'stock' => 10]);

        $this->actingAs($owner, 'web')->get('/app/pos')->assertInertia(fn ($page) => $page
            ->where('catalogMode', 'search')
            ->where('productCount', 3)
            ->has('products', 0)
        );
    }

    public function test_search_finds_a_product_by_name_barcode_or_generic_name(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Napa 500mg', 'generic_name' => 'Paracetamol', 'barcode' => 'NAPA500', 'cost' => 2, 'price' => 3, 'stock' => 100]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->getJson('/app/pos/search?q=Paracetamol')->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Napa 500mg');

        $this->actingAs($owner, 'web')->getJson('/app/pos/search?q=NAPA500')->assertOk()
            ->assertJsonCount(1, 'products');

        $this->actingAs($owner, 'web')->getJson('/app/pos/search?q=Rice')->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Rice');
    }

    public function test_search_respects_the_category_filter(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $category = \App\Models\ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Medicine']);
        Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Napa', 'cost' => 2, 'price' => 3, 'stock' => 10]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->getJson("/app/pos/search?category_id={$category->id}")->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Napa');
    }

    public function test_catalog_sync_chunk_is_forbidden_below_the_limit(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 100, 'stock' => 10]);

        $this->actingAs($owner, 'web')->getJson('/app/pos/catalog-sync')->assertForbidden();
    }

    public function test_catalog_sync_chunk_covers_every_product_exactly_once_across_multiple_chunks(): void
    {
        config(['pos.full_catalog_limit' => 2, 'pos.catalog_sync_chunk_size' => 3]);
        [$shop, $owner] = $this->createShopWithOwner();
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = Product::create(['shop_id' => $shop->id, 'name' => "Product {$i}", 'cost' => 10, 'price' => 20, 'stock' => 5])->id;
        }

        $seen = [];
        $afterId = null;
        $calls = 0;
        do {
            $calls++;
            $this->assertLessThan(10, $calls, 'catalog sync never terminated — likely an infinite loop in the cursor logic');
            $response = $this->actingAs($owner, 'web')
                ->getJson('/app/pos/catalog-sync'.($afterId ? "?after_id={$afterId}" : ''))
                ->assertOk();
            $chunkIds = collect($response->json('products'))->pluck('id')->all();
            $seen = array_merge($seen, $chunkIds);
            $afterId = $response->json('next_after_id');
        } while ($afterId !== null);

        // exactly once each, across 3 chunks (3+3+1) — no gaps, no duplicates
        $this->assertEquals(3, $calls);
        sort($seen);
        sort($ids);
        $this->assertEquals($ids, $seen);
    }
}
