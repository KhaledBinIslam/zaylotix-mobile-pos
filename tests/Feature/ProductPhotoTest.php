<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A real product photo is optional and additive — `emoji` stays as the
 * always-available fallback icon, `photo_url` is only ever set once a shop
 * actually uploads one. Mirrors the shop-logo upload pattern already
 * established in SettingController::updateLogo.
 */
class ProductPhotoTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creating_a_product_with_a_photo_stores_it_and_exposes_a_url(): void
    {
        Storage::fake('public');
        [$shop, $owner] = $this->createShopWithOwner();

        $response = $this->actingAs($owner, 'web')->post('/app/products', [
            'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5,
            'photo' => UploadedFile::fake()->image('rice.jpg'),
        ]);

        $response->assertRedirect();
        $product = Product::first();
        $this->assertNotNull($product->photo_path);
        Storage::disk('public')->assertExists($product->photo_path);
        $this->assertStringContainsString($product->photo_path, $product->photo_url);
    }

    public function test_a_product_without_a_photo_has_a_null_photo_url(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5]);

        $this->assertNull($product->photo_url);
    }

    public function test_replacing_a_photo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5, 'photo_path' => 'product-photos/old.jpg']);
        Storage::disk('public')->put('product-photos/old.jpg', 'fake-content');

        $this->actingAs($owner, 'web')->put("/app/products/{$product->id}", [
            'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5,
            'photo' => UploadedFile::fake()->image('new.jpg'),
        ])->assertRedirect();

        Storage::disk('public')->assertMissing('product-photos/old.jpg');
        $this->assertNotSame('product-photos/old.jpg', $product->fresh()->photo_path);
    }

    public function test_removing_a_photo_clears_it_and_deletes_the_file(): void
    {
        Storage::fake('public');
        [$shop, $owner] = $this->createShopWithOwner();
        Storage::disk('public')->put('product-photos/old.jpg', 'fake-content');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5, 'photo_path' => 'product-photos/old.jpg']);

        $this->actingAs($owner, 'web')->put("/app/products/{$product->id}", [
            'name' => 'Rice', 'cost' => 10, 'price' => 20, 'stock' => 5, 'remove_photo' => true,
        ])->assertRedirect();

        $this->assertNull($product->fresh()->photo_path);
        Storage::disk('public')->assertMissing('product-photos/old.jpg');
    }
}
