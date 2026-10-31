<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductColorBarcode;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A size added to an article AFTER it was created — from the stock
 * screen's colour x size grid, or one at a time — used to go in with no
 * barcode and no price of its own. Khaled: "oi serial e gia product add
 * korle purchase r sell price input diar option nai plus etar barcode o
 * create hocche na".
 *
 * Nothing here is new behaviour for a plain product: a grocery item
 * carrying a supplier's EAN must still get variants with no barcode,
 * which is what the last test pins.
 */
class ArticleVariantAddedLaterTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** Creates L-10001 at cost 1300 (encodes to NSXXZ) with one SILVER/36 variant, and returns [$shop, $owner, $product]. */
    private function anArticle(array $overrides = []): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'article_series');
        $this->grantFeature($shop, 'product_variants');

        $this->actingAs($owner, 'web')->post('/app/articles', array_merge([
            'client_uuid' => (string) Str::uuid(),
            'manufacturer_code' => 'SMS',
            'new_category_name' => "Women's Shoes",
            'category_code' => 'L',
            'cost' => 1300,
            'price' => 1990,
            'variants' => [['color' => 'SILVER', 'size' => '36', 'qty' => 2]],
        ], $overrides))->assertSessionHasNoErrors();

        return [$shop, $owner, Product::where('shop_id', $shop->id)->firstOrFail()];
    }

    public function test_a_size_added_from_the_grid_gets_the_articles_own_barcode(): void
    {
        [$shop, $owner, $product] = $this->anArticle();

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [
                ['color' => 'SILVER', 'size' => '38', 'stock' => 3],
                ['color' => 'SILVER', 'size' => '39', 'stock' => 1],
            ],
        ])->assertSessionHasNoErrors();

        $added = ProductVariant::where('product_id', $product->id)->whereIn('size', ['38', '39'])->get();
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER-38', $added->firstWhere('size', '38')->barcode);
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER-39', $added->firstWhere('size', '39')->barcode);
    }

    public function test_the_grid_takes_a_buying_and_selling_price_for_what_it_adds(): void
    {
        [$shop, $owner, $product] = $this->anArticle();

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['color' => 'SILVER', 'size' => '38', 'stock' => 3]],
            'cost' => 1500,
            'price' => 2200,
        ])->assertSessionHasNoErrors();

        $added = ProductVariant::where('product_id', $product->id)->where('size', '38')->firstOrFail();
        $this->assertEquals(1500, $added->cost);
        $this->assertEquals(2200, $added->price);
        // and that cost is what the sticker now hides — 1500 encodes to NRXXZ
        $this->assertSame('SMS-L-10001-NRXXZ-SILVER-38', $added->barcode);
    }

    public function test_a_size_group_article_takes_one_price_pair_per_group(): void
    {
        [$shop, $owner, $product] = $this->anArticle();
        $category = ProductCategory::where('shop_id', $shop->id)->firstOrFail();
        $shop->update(['size_group_category_codes' => [$category->code]]);

        // defaults: Small 21-28, Medium 29-32, Large 33-37
        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [
                ['color' => 'SILVER', 'size' => '25', 'stock' => 1],
                ['color' => 'SILVER', 'size' => '35', 'stock' => 1],
            ],
            'group_costs' => ['Small' => 500, 'Medium' => 1300, 'Large' => 1500],
            'group_prices' => ['Small' => 1000, 'Medium' => 1900, 'Large' => 2200],
        ])->assertSessionHasNoErrors();

        $small = ProductVariant::where('product_id', $product->id)->where('size', '25')->firstOrFail();
        $large = ProductVariant::where('product_id', $product->id)->where('size', '35')->firstOrFail();

        $this->assertEquals(500, $small->cost);
        $this->assertEquals(1000, $small->price);
        $this->assertEquals(1500, $large->cost);
        $this->assertEquals(2200, $large->price);

        // each size's sticker hides what THAT size cost: 500 -> RXXZ, 1500 -> NRXXZ
        $this->assertSame('SMS-L-10001-RXXZ-SILVER-25', $small->barcode);
        $this->assertSame('SMS-L-10001-NRXXZ-SILVER-35', $large->barcode);
    }

    public function test_a_size_outside_every_group_is_refused_rather_than_priced_by_guess(): void
    {
        [$shop, $owner, $product] = $this->anArticle();
        $category = ProductCategory::where('shop_id', $shop->id)->firstOrFail();
        $shop->update(['size_group_category_codes' => [$category->code]]);

        // 18 falls below Small (21-28) — inheriting the article's headline
        // price would be a wrong number printed on a sticker
        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['color' => 'SILVER', 'size' => '18', 'stock' => 1]],
            'group_costs' => ['Small' => 500],
            'group_prices' => ['Small' => 1000],
        ])->assertSessionHasErrors('size');

        $this->assertNull(ProductVariant::where('product_id', $product->id)->where('size', '18')->first());
    }

    public function test_a_colour_introduced_later_gets_its_own_sticker_barcode(): void
    {
        [$shop, $owner, $product] = $this->anArticle();

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['color' => 'KHAKI', 'size' => '38', 'stock' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'SMS-L-10001-NSXXZ-KHAKI',
            ProductColorBarcode::where('product_id', $product->id)->where('color', 'KHAKI')->value('barcode')
        );
    }

    public function test_adding_one_variant_at_a_time_fills_a_blank_barcode_but_never_a_typed_one(): void
    {
        [$shop, $owner, $product] = $this->anArticle();

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'color' => 'SILVER', 'size' => '40', 'stock' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(
            'SMS-L-10001-NSXXZ-SILVER-40',
            ProductVariant::where('product_id', $product->id)->where('size', '40')->value('barcode')
        );

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'color' => 'SILVER', 'size' => '41', 'stock' => 1, 'barcode' => 'MY-OWN-CODE',
        ])->assertSessionHasNoErrors();
        $this->assertSame(
            'MY-OWN-CODE',
            ProductVariant::where('product_id', $product->id)->where('size', '41')->value('barcode')
        );
    }

    public function test_removing_a_size_hands_its_barcode_back_so_it_can_be_re_added(): void
    {
        [$shop, $owner, $product] = $this->anArticle();
        $variant = ProductVariant::where('product_id', $product->id)->where('size', '36')->firstOrFail();
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER-36', $variant->barcode);

        $this->actingAs($owner, 'web')->delete("/app/product-variants/{$variant->id}")->assertRedirect();

        // a soft-deleted row keeps occupying the unique (shop_id, barcode)
        // index, and the same size rebuilds the exact same string — so
        // holding onto it meant 36 could never get its barcode again
        $this->assertNull(ProductVariant::withoutGlobalScopes()->whereKey($variant->id)->value('barcode'));

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants", [
            'color' => 'SILVER', 'size' => '36', 'stock' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'SMS-L-10001-NSXXZ-SILVER-36',
            ProductVariant::where('product_id', $product->id)->where('size', '36')->value('barcode')
        );
    }

    public function test_the_migration_frees_barcodes_held_by_sizes_removed_earlier(): void
    {
        [$shop, $owner, $product] = $this->anArticle();
        $variant = ProductVariant::where('product_id', $product->id)->where('size', '36')->firstOrFail();

        // the old behaviour: soft-deleted with its barcode still on it
        ProductVariant::withoutGlobalScopes()->whereKey($variant->id)->update(['deleted_at' => now()]);
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->whereKey($variant->id)->value('barcode'));

        (require base_path('database/migrations/2026_10_31_000005_release_barcodes_from_already_deleted_variants.php'))->up();

        $this->assertNull(ProductVariant::withoutGlobalScopes()->whereKey($variant->id)->value('barcode'));

        // which is what lets the backfill give the re-added size one
        ProductVariant::create([
            'shop_id' => $shop->id, 'product_id' => $product->id,
            'size' => '36', 'color' => 'SILVER', 'stock' => 2,
        ]);
        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--variants-only' => true])->assertSuccessful();

        $this->assertSame(
            'SMS-L-10001-NSXXZ-SILVER-36',
            ProductVariant::where('product_id', $product->id)->where('size', '36')->value('barcode')
        );
    }

    public function test_variants_only_never_touches_a_colour_barcode(): void
    {
        [$shop, $owner, $product] = $this->anArticle();
        // what a cost correction after the stickers were printed leaves
        // behind: the colour barcode still carries the OLD article code
        ProductColorBarcode::where('product_id', $product->id)->update(['barcode' => 'SMS-L-10001-OLDZ-SILVER']);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id, '--variants-only' => true])->assertSuccessful();
        $this->assertSame('SMS-L-10001-OLDZ-SILVER', ProductColorBarcode::where('product_id', $product->id)->value('barcode'));

        // without the flag it is rebuilt, as it always was
        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])->assertSuccessful();
        $this->assertSame('SMS-L-10001-NSXXZ-SILVER', ProductColorBarcode::where('product_id', $product->id)->value('barcode'));
    }

    public function test_a_plain_product_still_gets_variants_with_no_barcode(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        // a supplier's own EAN, not an article barcode — minting
        // "5901234123457-RED-M" off the back of it would invent a code
        // that nothing has ever printed
        $product = Product::create([
            'shop_id' => $shop->id, 'name' => 'Shirt', 'barcode' => '5901234123457',
            'cost' => 200, 'price' => 400, 'stock' => 0,
        ]);

        $this->actingAs($owner, 'web')->post("/app/products/{$product->id}/variants/bulk", [
            'variants' => [['color' => 'RED', 'size' => 'M', 'stock' => 4]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(ProductVariant::where('product_id', $product->id)->value('barcode'));
    }

    public function test_the_backfill_command_fills_in_variants_that_went_in_without_one(): void
    {
        [$shop, $owner, $product] = $this->anArticle();

        // exactly the rows the grid used to create: stock only
        ProductVariant::create([
            'shop_id' => $shop->id, 'product_id' => $product->id,
            'size' => '38', 'color' => 'SILVER', 'stock' => 1,
        ]);

        $this->artisan('articles:regenerate-barcodes', ['--shop' => $shop->id])->assertSuccessful();

        $this->assertSame(
            'SMS-L-10001-NSXXZ-SILVER-38',
            ProductVariant::where('product_id', $product->id)->where('size', '38')->value('barcode')
        );
        // the one created WITH a barcode is left exactly as it was — it may
        // already be printed and stuck on a shoe
        $this->assertSame(
            'SMS-L-10001-NSXXZ-SILVER-36',
            ProductVariant::where('product_id', $product->id)->where('size', '36')->value('barcode')
        );
    }
}
