<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN, 2026-11-25: staff deleted the "Boiler Suit with Radium" article by mistake. The model's restore()
 * would also have resurrected the sizes the owner removed on purpose weeks earlier — this command must not.
 */
class ProductsRestoreDeletedTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    /** A plain product with 3 live sizes and 1 size the owner deleted long before the product itself went. */
    private function deletedProduct(string $name = 'Boiler Suit'): array
    {
        [$shop] = $this->createShopWithOwner();
        $p = Product::create(['shop_id' => $shop->id, 'name' => $name, 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $mk = fn ($size, $stock) => ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => $size, 'color' => 'Navy', 'stock' => $stock, 'barcode' => 'ZV-OLD-'.$size]);
        $old = $mk('S', 5);
        $a = $mk('M', 10);
        $b = $mk('L', 20);
        $c = $mk('XL', 30);
        $p->forceFill(['stock' => 60])->save();

        $this->travelTo(now()->subWeeks(3));
        $old->delete();               // deliberate removal, three weeks ago
        $this->travelBack();

        $p->refresh()->delete();      // the mistake — hides the live sizes and hands every barcode back

        return [$shop, $p, $old, [$a, $b, $c]];
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        [, $p] = $this->deletedProduct();

        $this->artisan('products:restore', ['product' => $p->id])->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertNotNull(Product::withoutGlobalScopes()->withTrashed()->find($p->id)->deleted_at);
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->whereNull('deleted_at')->where('product_id', $p->id)->count());
    }

    public function test_apply_brings_back_only_the_sizes_that_went_down_with_the_product(): void
    {
        [$shop, $p, $old, $mistaken] = $this->deletedProduct();

        $this->artisan('products:restore', ['product' => $p->id, '--apply' => true])->expectsOutputToContain('RESTORED')->assertSuccessful();

        $this->assertNull(Product::withoutGlobalScopes()->withTrashed()->find($p->id)->deleted_at, 'the product is live again');
        $live = ProductVariant::withoutGlobalScopes()->whereNull('deleted_at')->where('product_id', $p->id)->get();
        $this->assertEqualsCanonicalizing(collect($mistaken)->pluck('id')->all(), $live->pluck('id')->all());
        $this->assertSame(60.0, (float) $live->sum('stock'), 'stock is exactly what it was');
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->withTrashed()->find($old->id)->deleted_at, 'the size the owner removed on purpose stays removed');
        foreach ($live as $v) {
            $this->assertSame('ZV'.$v->id, $v->barcode, 'a plain product gets its ZV{id} barcode back so old stickers still scan');
        }
        $this->assertDatabaseHas('activity_logs', ['shop_id' => $shop->id, 'action' => 'products.restored', 'subject_id' => $p->id]);
    }

    public function test_an_article_is_not_given_a_zv_barcode(): void
    {
        [, $p] = $this->deletedProduct('B-20069');

        $this->artisan('products:restore', ['product' => $p->id, '--apply' => true])->assertSuccessful();

        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->whereNull('deleted_at')->where('product_id', $p->id)->whereNotNull('barcode')->count(), 'an article\'s barcode comes from articles:regenerate-barcodes');
    }

    public function test_a_zv_code_another_row_already_holds_is_skipped_not_forced(): void
    {
        [$shop, $p, , $mistaken] = $this->deletedProduct();
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => Product::create(['shop_id' => $shop->id, 'name' => 'Other', 'cost' => 1, 'price' => 2, 'stock' => 0])->id, 'size' => 'X', 'stock' => 0, 'barcode' => 'ZV'.$mistaken[0]->id]);

        $this->artisan('products:restore', ['product' => $p->id, '--apply' => true])->assertSuccessful();

        $this->assertNull(ProductVariant::withoutGlobalScopes()->find($mistaken[0]->id)->barcode);
        $this->assertSame('ZV'.$mistaken[1]->id, ProductVariant::withoutGlobalScopes()->find($mistaken[1]->id)->barcode);
    }

    public function test_a_live_or_unknown_product_is_refused(): void
    {
        $this->artisan('products:restore', ['product' => 999999, '--apply' => true])->assertFailed();
    }
}
