<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 2 — category → sub-category (feature: sub_categories).
 * "Show me shoes" includes every kind of shoe; two levels maximum;
 * deleting never orphans; other shops see and change nothing.
 */
class SubCategoryTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aShopWithTheFeature(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'sub_categories');

        return [$shop, $owner];
    }

    public function test_the_page_is_refused_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();

        $this->actingAs($owner, 'web')->get(route('app.categories.index'))->assertForbidden();
    }

    public function test_a_parent_category_filter_includes_its_sub_categories_in_stock(): void
    {
        [$shop, $owner] = $this->aShopWithTheFeature();
        $shoe = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe']);
        $safety = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Safety Shoe', 'parent_id' => $shoe->id]);
        $gumboot = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Gumboot', 'parent_id' => $shoe->id]);

        Product::create(['shop_id' => $shop->id, 'name' => 'Lotus', 'category_id' => $safety->id, 'cost' => 1, 'price' => 2, 'stock' => 1]);
        Product::create(['shop_id' => $shop->id, 'name' => 'JCD High', 'category_id' => $gumboot->id, 'cost' => 1, 'price' => 2, 'stock' => 1]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Direct-on-shoe', 'category_id' => $shoe->id, 'cost' => 1, 'price' => 2, 'stock' => 1]);
        $helmetCat = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Safety Equipment']);
        Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'category_id' => $helmetCat->id, 'cost' => 1, 'price' => 2, 'stock' => 1]);

        // parent -> all three shoes, never the helmet
        $this->actingAs($owner, 'web')->get(route('app.stock', ['category_id' => $shoe->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 3)->etc());
        // a sub-category stays its narrow self
        $this->actingAs($owner, 'web')->get(route('app.stock', ['category_id' => $safety->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->etc());
    }

    public function test_three_levels_are_refused(): void
    {
        [$shop, $owner] = $this->aShopWithTheFeature();
        $shoe = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe']);
        $safety = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Safety Shoe', 'parent_id' => $shoe->id]);

        $this->actingAs($owner, 'web')->post(route('app.categories.store'), [
            'name' => 'Steel Toe', 'parent_id' => $safety->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertSame(0, ProductCategory::withoutGlobalScopes()->where('name', 'Steel Toe')->count());
    }

    public function test_a_parent_with_children_cannot_be_moved_under_another(): void
    {
        [$shop, $owner] = $this->aShopWithTheFeature();
        $shoe = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe']);
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Safety Shoe', 'parent_id' => $shoe->id]);
        $others = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Others']);

        $this->actingAs($owner, 'web')->patch(route('app.categories.update', $shoe->id), [
            'name' => 'Shoe', 'parent_id' => $others->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_another_shops_category_cannot_be_a_parent(): void
    {
        [$shopA, $ownerA] = $this->aShopWithTheFeature();
        [$shopB] = $this->createShopWithOwner();
        $foreign = ProductCategory::create(['shop_id' => $shopB->id, 'name' => 'Foreign']);

        $this->actingAs($ownerA, 'web')->post(route('app.categories.store'), [
            'name' => 'Sneaky', 'parent_id' => $foreign->id,
        ])->assertSessionHasErrors('parent_id');
    }

    public function test_deleting_a_category_with_products_moves_them_where_the_owner_says(): void
    {
        [$shop, $owner] = $this->aShopWithTheFeature();
        $shoe = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe']);
        $old = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Old Kind', 'parent_id' => $shoe->id]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'category_id' => $old->id, 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $this->actingAs($owner, 'web')->delete(route('app.categories.destroy', $old->id), [
            'move_products_to' => $shoe->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($shoe->id, $product->fresh()->category_id);
        $this->assertNull(ProductCategory::withoutGlobalScopes()->find($old->id));
    }

    public function test_deleting_a_parent_with_children_is_refused(): void
    {
        [$shop, $owner] = $this->aShopWithTheFeature();
        $shoe = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Shoe']);
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Safety Shoe', 'parent_id' => $shoe->id]);

        $this->actingAs($owner, 'web')->delete(route('app.categories.destroy', $shoe->id))
            ->assertSessionHasErrors('category');
        $this->assertNotNull(ProductCategory::withoutGlobalScopes()->find($shoe->id));
    }

    public function test_a_flat_shops_stock_filter_is_unchanged(): void
    {
        // Baby Park's world: no children anywhere — the whereIn collapses
        // to [id] and behaves exactly like the old equality filter
        [$shop, $owner] = $this->createShopWithOwner();
        $cat = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Ladies']);
        Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'category_id' => $cat->id, 'cost' => 1, 'price' => 2, 'stock' => 1]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Uncategorised', 'cost' => 1, 'price' => 2, 'stock' => 1]);

        $this->actingAs($owner, 'web')->get(route('app.stock', ['category_id' => $cat->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 1)->etc());
    }
}
