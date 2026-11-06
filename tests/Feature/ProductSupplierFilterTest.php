<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled live: "supplier filter option lagbe barcode print e plus stock e
 * plus product stock e" — once Part 1 lines let a product carry several
 * real suppliers, the Stock page (ProductController::index) and Barcode
 * Labels page (BarcodeLabelController::index) both need a way to filter by
 * one, matching the ACTUAL lines for a source_lines shop rather than the
 * single, one-time supplier_id field.
 */
class ProductSupplierFilterTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_stock_page_filters_by_the_plain_supplier_id_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);

        $bulu = Supplier::create(['name' => 'Bulu']);
        $sujon = Supplier::create(['name' => 'Sujon']);
        Product::create(['shop_id' => $shop->id, 'name' => 'From Bulu', 'cost' => 1, 'price' => 2, 'stock' => 1, 'supplier_id' => $bulu->id]);
        Product::create(['shop_id' => $shop->id, 'name' => 'From Sujon', 'cost' => 1, 'price' => 2, 'stock' => 1, 'supplier_id' => $sujon->id]);

        $this->actingAs($owner, 'web')->get("/app/stock?supplier_id={$sujon->id}")
            ->assertOk()->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'From Sujon')
            );
    }

    public function test_stock_page_filters_by_a_lines_supplier_on_a_source_lines_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);

        $bulu = Supplier::create(['name' => 'Bulu']);
        $sujon = Supplier::create(['name' => 'Sujon']);

        // the product's OWN single supplier_id still says Bulu — set once,
        // long before any line existed — but its real stock now also came
        // from Sujon via a second line on one of its variants
        $suit = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 6, 'supplier_id' => $bulu->id]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'size' => 'M', 'stock' => 6]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'product_variant_id' => $m->id, 'supplier_id' => $bulu->id, 'cost' => 650, 'price' => 1090, 'stock' => 4]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'product_variant_id' => $m->id, 'supplier_id' => $sujon->id, 'cost' => 700, 'price' => 1090, 'stock' => 2]);

        $other = Product::create(['shop_id' => $shop->id, 'name' => 'Unrelated', 'cost' => 1, 'price' => 2, 'stock' => 1, 'supplier_id' => $bulu->id]);

        $this->actingAs($owner, 'web')->get("/app/stock?supplier_id={$sujon->id}")
            ->assertOk()->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $suit->id)
            );

        // Bulu still finds it too (it has a Bulu line as well as a Sujon
        // one) — filtering is "has a matching line", not "has ONLY it"
        $this->actingAs($owner, 'web')->get("/app/stock?supplier_id={$bulu->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->has('products.data', 2));
    }

    public function test_barcode_labels_page_sends_supplier_ids_and_the_supplier_list(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants', 'barcode_printing'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);

        $sujon = Supplier::create(['name' => 'Sujon']);
        $helmet = Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'cost' => 100, 'price' => 200, 'stock' => 3, 'barcode' => 'ZP1']);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $helmet->id, 'product_variant_id' => null, 'supplier_id' => $sujon->id, 'cost' => 100, 'price' => 200, 'stock' => 3, 'barcode' => 'ZL1']);

        $this->actingAs($owner, 'web')->get(route('app.barcodeLabels.index'))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('products.0.sources.0.supplier_id', $sujon->id)
                ->where('suppliers.0.name', 'Sujon')
            );
    }

    /**
     * Khaled live: "sujon search korchi kichu asena" — product.search_text
     * never knew about a supplier that only existed on a LINE, so typing
     * the supplier's name in the Stock page's own text search (as opposed
     * to the dropdown filter above) found nothing. ProductSource::
     * upsertLine() touches the product on a brand-new line specifically
     * so this stays correct without a separate reindex step.
     */
    public function test_text_search_finds_a_product_by_a_lines_supplier_name(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);

        $manik = Supplier::create(['name' => 'Manik']);
        $sujon = Supplier::create(['name' => 'Sujon']);
        // the product's OWN field still says Manik — only the line says Sujon
        $suit = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 4, 'supplier_id' => $manik->id]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'size' => 'M', 'stock' => 4]);
        ProductSource::upsertLine($shop->id, $suit->id, $m->id, $sujon->id, null, 4, 700, 1090, 1090);

        $this->actingAs($owner, 'web')->get('/app/stock?q=Sujon')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.id', $suit->id)
            );
    }

    public function test_the_supplier_filter_dropdown_is_hidden_without_the_suppliers_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'barcode_printing');
        \App\Support\Tenancy::set($shop->id);

        $this->actingAs($owner, 'web')->get('/app/stock')
            ->assertOk()->assertInertia(fn ($page) => $page->where('suppliers', null));

        $this->actingAs($owner, 'web')->get(route('app.barcodeLabels.index'))
            ->assertOk()->assertInertia(fn ($page) => $page->where('suppliers', null));
    }
}
