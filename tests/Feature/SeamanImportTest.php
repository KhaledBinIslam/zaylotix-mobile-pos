<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 6 — the CSV importer. Runs against the real committed
 * database/data/seaman-products.csv, so these tests pin exactly what the
 * server run will do: grouping, axis assignment, the brand-as-axis
 * helmet case, alt-supplier rows, zero stock, notes, idempotency.
 */
class SeamanImportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function anImportShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['sub_categories', 'variant_options', 'brands', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }

        return [$shop, $owner];
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$shop] = $this->anImportShop();

        $this->artisan('products:import-seaman', ['--shop' => $shop->id, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_import_expands_the_owner_list_correctly_and_is_idempotent(): void
    {
        [$shop] = $this->anImportShop();

        $this->artisan('products:import-seaman', ['--shop' => $shop->id])
            ->expectsOutputToContain('CHECK THESE')
            ->assertSuccessful();

        $product = fn (string $name) => Product::withoutGlobalScopes()
            ->where('shop_id', $shop->id)->where('name', $name)->sole();
        $variants = fn (Product $p) => ProductVariant::withoutGlobalScopes()->where('product_id', $p->id);

        // 18 colours × 7 sizes, plain colour/size axes
        $boiler = $product('Boiler Suit Plain');
        $this->assertSame(126, $variants($boiler)->count());
        $this->assertNull($boiler->option1_label);

        // the helmet case: 5 colours × 4 brands — brand becomes the second
        // axis AND every variant carries its own brand_id and price
        $helmet = $product('Helmet');
        $this->assertSame(20, $variants($helmet)->count());
        $this->assertSame('Brand', $helmet->option2_label);
        $this->assertSame(20, $variants($helmet)->whereNotNull('brand_id')->count());
        $crownWhite = $variants($helmet)->where('size', 'Crown')->where('color', 'White')->sole();
        $this->assertEquals(150, (float) $crownWhite->price);

        // a row differing only by supplier/price is the SAME shoe — no
        // duplicate variants, first row's cost kept, alternative noted
        $powerX = $product('Power X Safety Shoe');
        $this->assertSame(7, $variants($powerX)->count()); // sizes 39-45 once
        $this->assertEquals(1600, (float) $powerX->cost);
        $this->assertStringContainsString('Jubilee', (string) $powerX->internal_note);

        // 26 ranks on a renamed axis
        $epaulettes = $product('Epaulettes');
        $this->assertSame(26, $variants($epaulettes)->count());
        $this->assertSame('Rank / Type', $epaulettes->option2_label);

        // stock is 0 everywhere — the owner adds his own counts (Phase 6B)
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->where('stock', '>', 0)->count());
        $this->assertSame(0, ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->where('stock', '>', 0)->count());

        // sub-categories hang under their parents
        $safetyShoe = ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'Safety Shoe')->sole();
        $this->assertNotNull($safetyShoe->parent_id);

        // run twice: nothing duplicates, nothing changes
        $before = [
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
            ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
        ];
        $this->artisan('products:import-seaman', ['--shop' => $shop->id])->assertSuccessful();
        $this->assertSame($before, [
            Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
            ProductVariant::withoutGlobalScopes()->where('shop_id', $shop->id)->count(),
        ]);
    }
}
