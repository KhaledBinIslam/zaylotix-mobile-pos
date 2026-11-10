<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled wanted purchase/sell price editable right from a variant's own
 * row on the Stock page (not a separate page), with a way to see when it
 * was last changed.
 */
class ProductVariantUpdatePriceTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_updates_the_variants_own_cost_and_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sandal', 'name_en' => 'Sandal', 'cost' => 300, 'price' => 450, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'BLACK', 'cost' => 300, 'price' => 450, 'stock' => 5]);

        $this->actingAs($owner)->post(route('app.productVariants.updatePrice', $variant->id), [
            'cost' => 320, 'price' => 480,
        ])->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame(320.0, (float) $variant->cost);
        $this->assertSame(480.0, (float) $variant->price);
    }

    public function test_logs_an_activity_entry_visible_via_the_existing_history_endpoint(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sandal', 'name_en' => 'Sandal', 'cost' => 300, 'price' => 450, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'BLACK', 'cost' => 300, 'price' => 450, 'stock' => 5]);

        $this->actingAs($owner)->post(route('app.productVariants.updatePrice', $variant->id), [
            'cost' => 320, 'price' => 480,
        ]);

        $this->assertSame(1, ActivityLog::where('subject_type', ProductVariant::class)->where('subject_id', $variant->id)->where('action', 'product.variant.priceUpdate')->count());

        $response = $this->actingAs($owner)->getJson(route('app.productVariants.history', $variant->id));
        $response->assertOk();
        $response->assertJsonFragment(['action' => 'product.variant.priceUpdate']);
    }

    public function test_no_activity_entry_when_nothing_actually_changed(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sandal', 'name_en' => 'Sandal', 'cost' => 300, 'price' => 450, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'BLACK', 'cost' => 300, 'price' => 450, 'stock' => 5]);

        $this->actingAs($owner)->post(route('app.productVariants.updatePrice', $variant->id), [
            'cost' => 300, 'price' => 450,
        ]);

        $this->assertSame(0, ActivityLog::where('subject_type', ProductVariant::class)->where('subject_id', $variant->id)->count());
    }

    public function test_requires_cost_and_price(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sandal', 'name_en' => 'Sandal', 'cost' => 300, 'price' => 450, 'stock' => 5]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'BLACK', 'cost' => 300, 'price' => 450, 'stock' => 5]);

        $this->actingAs($owner)->post(route('app.productVariants.updatePrice', $variant->id), [])
            ->assertSessionHasErrors(['cost', 'price']);
    }
}
