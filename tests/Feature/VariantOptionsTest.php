<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SizeSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 3 — variants beyond colour/size: a product may NAME its
 * two axes (Rank × Type), a grid cell may carry its own price/cost, and
 * categories carry default size sets. Everything rides the existing
 * color/size columns, so Baby Park's flow is untouched by construction.
 */
class VariantOptionsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_option_labels_save_only_for_a_shop_with_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'variant_options');

        $this->actingAs($owner, 'web')->post(route('app.products.store'), [
            'name' => 'Epaulettes', 'cost' => 100, 'price' => 150, 'stock' => 0,
            'option1_label' => 'Rank', 'option2_label' => 'Type',
        ])->assertSessionHasNoErrors();

        $p = Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'Epaulettes')->first();
        $this->assertSame('Rank', $p->option1_label);
        $this->assertSame('Type', $p->option2_label);

        // a shop WITHOUT the feature sends the same fields and they stay null
        [$plainShop, $plainOwner] = $this->createShopWithOwner();
        $this->actingAs($plainOwner, 'web')->post(route('app.products.store'), [
            'name' => 'Shirt', 'cost' => 100, 'price' => 150, 'stock' => 0,
            'option1_label' => 'Rank', 'option2_label' => 'Type',
        ])->assertSessionHasNoErrors();

        $plain = Product::withoutGlobalScopes()->where('shop_id', $plainShop->id)->where('name', 'Shirt')->first();
        $this->assertNull($plain->option1_label);
        $this->assertNull($plain->option2_label);
    }

    public function test_a_grid_cell_may_carry_its_own_price_and_cost(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Epaulettes', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.bulkStore', $product->id), [
            'variants' => [
                ['color' => 'Chief Officer', 'size' => 'Soft', 'stock' => 2, 'price' => 500, 'cost' => 300],
                ['color' => 'Chief Officer', 'size' => 'Hard', 'stock' => 2, 'price' => 700, 'cost' => 350],
                ['color' => 'Master', 'size' => 'Soft', 'stock' => 1], // no own price — inherits, as always
            ],
        ])->assertSessionHasNoErrors();

        $soft = ProductVariant::where('product_id', $product->id)->where('color', 'Chief Officer')->where('size', 'Soft')->first();
        $hard = ProductVariant::where('product_id', $product->id)->where('color', 'Chief Officer')->where('size', 'Hard')->first();
        $master = ProductVariant::where('product_id', $product->id)->where('color', 'Master')->first();

        $this->assertEquals(500, $soft->price);
        $this->assertEquals(300, $soft->cost);
        $this->assertEquals(700, $hard->price);
        $this->assertEquals(350, $hard->cost);
        $this->assertNull($master->price); // old behaviour, untouched
        $this->assertNull($master->cost);
    }

    public function test_a_flat_shops_bulk_grid_without_cell_prices_behaves_exactly_as_before(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->actingAs($owner, 'web')->post(route('app.productVariants.bulkStore', $product->id), [
            'variants' => [['color' => 'Black', 'size' => '42', 'stock' => 3]],
            'price' => 200, 'cost' => 120,
        ])->assertSessionHasNoErrors();

        $v = ProductVariant::where('product_id', $product->id)->first();
        $this->assertEquals(200, $v->price);
        $this->assertEquals(120, $v->cost);
    }

    public function test_size_set_crud_is_feature_gated_and_tenant_scoped(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'variant_options');

        $this->actingAs($owner, 'web')->post(route('app.sizeSets.store'), [
            'name' => 'Peak cap', 'sizes' => '20.5, 21 , 21.5,22',
        ])->assertSessionHasNoErrors();

        $set = SizeSet::withoutGlobalScopes()->where('shop_id', $shop->id)->first();
        $this->assertSame(['20.5', '21', '21.5', '22'], $set->sizes); // trimmed, order kept

        // another shop — WITH the feature — still can't touch it: the
        // tenant-scoped binding simply doesn't find a foreign set
        [$otherShop, $otherOwner] = $this->createShopWithOwner();
        $this->grantFeature($otherShop, 'variant_options');
        $this->actingAs($otherOwner, 'web')->patch(route('app.sizeSets.update', $set->id), [
            'name' => 'X', 'sizes' => '1',
        ])->assertNotFound();

        $this->assertSame('Peak cap', $set->fresh()->name);
    }

    public function test_a_category_can_name_its_default_size_set_and_a_foreign_set_is_refused(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'sub_categories');
        $this->grantFeature($shop, 'variant_options');
        $cat = ProductCategory::create(['shop_id' => $shop->id, 'name' => 'Cap']);
        $set = SizeSet::create(['shop_id' => $shop->id, 'name' => 'Peak cap', 'sizes' => ['20.5', '21']]);

        $resp = $this->actingAs($owner, 'web')->patch(route('app.categories.update', $cat->id), [
            'name' => 'Cap', 'size_set_id' => $set->id,
        ]);
        $resp->assertSessionHasNoErrors();
        $this->assertSame($set->id, $cat->fresh()->size_set_id);

        // another shop's set id simply isn't found here
        [$shopB] = $this->createShopWithOwner();
        $foreign = SizeSet::create(['shop_id' => $shopB->id, 'name' => 'Foreign', 'sizes' => ['1']]);
        $this->actingAs($owner, 'web')->patch(route('app.categories.update', $cat->id), [
            'name' => 'Cap', 'size_set_id' => $foreign->id,
        ])->assertSessionHasErrors('size_set_id');
    }

    public function test_setup_seaman_seeds_the_owners_size_sets_idempotently(): void
    {
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-1'])->assertSuccessful();
        $this->artisan('shop:setup-seaman', ['--password' => 'secret-for-test-1'])->assertSuccessful();

        $shop = \App\Models\Shop::withoutGlobalScopes()->where('phone', '01833722077')->first();
        $sets = SizeSet::withoutGlobalScopes()->where('shop_id', $shop->id)->get();

        $this->assertSame(10, $sets->count()); // re-run refreshed, never duplicated
        $peak = $sets->firstWhere('name', 'Peak cap 20½–23');
        $this->assertSame(['20.5', '21', '21.5', '22', '22.5', '23'], $peak->sizes);
        $this->assertTrue($shop->hasFeature('variant_options'));
    }
}
