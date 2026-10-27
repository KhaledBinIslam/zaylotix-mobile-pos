<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductColorBarcode;
use App\Models\ProductColorBarcodeAlias;
use App\Support\ColorBarcodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** docs/ARTICLE_SERIES_PLAN.md §9 — the one place a ProductColorBarcode row is ever written. */
class ColorBarcodeResolverTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_creates_a_new_row_for_a_color_never_seen_before(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 0]);

        $row = ColorBarcodeResolver::resolve($shop, $product, 'SILVER', 'SMS-L-10001-NSXXZ');

        $this->assertSame('SMS-L-10001-NSXXZ-SILVER', $row->barcode);
        $this->assertSame(1, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    public function test_calling_it_again_with_the_same_inputs_is_a_no_op(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 0]);

        $first = ColorBarcodeResolver::resolve($shop, $product, 'SILVER', 'SMS-L-10001-NSXXZ');
        $second = ColorBarcodeResolver::resolve($shop, $product, 'SILVER', 'SMS-L-10001-NSXXZ');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());
        $this->assertSame(0, ProductColorBarcodeAlias::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }

    /** Real scenario this exists for: Khaled renames a color's code later -- the old sticker must stay scannable. */
    public function test_a_changed_color_code_aliases_the_old_barcode_instead_of_losing_it(): void
    {
        [$shop] = $this->createShopWithOwner(['color_code_map' => ['SILVER' => 'SIL']]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 0]);

        ColorBarcodeResolver::resolve($shop, $product, 'SILVER', 'SMS-L-10001-NSXXZ');

        $shop->update(['color_code_map' => ['SILVER' => 'SLV']]); // owner renames the code
        $row = ColorBarcodeResolver::resolve($shop, $product->fresh(), 'SILVER', 'SMS-L-10001-NSXXZ');

        $this->assertSame('SMS-L-10001-NSXXZ-SLV', $row->barcode);
        $alias = ProductColorBarcodeAlias::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertNotNull($alias);
        $this->assertSame('SMS-L-10001-NSXXZ-SIL', $alias->barcode); // the OLD sticker, still resolvable
    }

    public function test_two_different_colors_of_the_same_product_get_their_own_rows(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'L-10001', 'cost' => 1300, 'price' => 1990, 'stock' => 0]);

        $silver = ColorBarcodeResolver::resolve($shop, $product, 'SILVER', 'SMS-L-10001-NSXXZ');
        $khaki = ColorBarcodeResolver::resolve($shop, $product, 'KHAKI', 'SMS-L-10001-NSXXZ');

        $this->assertNotSame($silver->barcode, $khaki->barcode);
        $this->assertSame(2, ProductColorBarcode::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }
}
