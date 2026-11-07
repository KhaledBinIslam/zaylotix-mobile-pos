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
 * Khaled live: "Sujon" and "Sujon 3rd party" — two real, separate
 * suppliers, indistinguishable in a plain name-only dropdown. Every
 * supplier picker now carries a usage count so the one with no data
 * ("Sujon (0)") is never mistaken for the one that does.
 */
class SupplierPickerListTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_counts_distinct_products_by_their_lines_on_a_source_lines_shop(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);

        $sujon = Supplier::create(['name' => 'Sujon']);
        $sujon3rd = Supplier::create(['name' => 'Sujon 3rd party']);

        $suit = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'size' => 'M', 'stock' => 0]);
        $l = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'size' => 'L', 'stock' => 0]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'product_variant_id' => $m->id, 'supplier_id' => $sujon3rd->id, 'cost' => 700, 'price' => 1090, 'stock' => 4]);
        // a SECOND variant of the SAME product, same supplier — must not double-count the product
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $suit->id, 'product_variant_id' => $l->id, 'supplier_id' => $sujon3rd->id, 'cost' => 700, 'price' => 1090, 'stock' => 2]);

        $list = Supplier::pickerList()->keyBy('name');
        $this->assertSame(0, $list['Sujon']['count']);
        $this->assertSame(1, $list['Sujon 3rd party']['count']); // one distinct product, not two lines
    }

    public function test_counts_by_the_plain_supplier_id_without_source_lines(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);

        $bulu = Supplier::create(['name' => 'Bulu']);
        $sujon = Supplier::create(['name' => 'Sujon']);
        Product::create(['shop_id' => $shop->id, 'name' => 'A', 'cost' => 1, 'price' => 2, 'stock' => 1, 'supplier_id' => $bulu->id]);
        Product::create(['shop_id' => $shop->id, 'name' => 'B', 'cost' => 1, 'price' => 2, 'stock' => 1, 'supplier_id' => $bulu->id]);

        $list = Supplier::pickerList()->keyBy('name');
        $this->assertSame(2, $list['Bulu']['count']);
        $this->assertSame(0, $list['Sujon']['count']);
    }

    public function test_stock_page_sends_the_count_on_every_supplier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        Supplier::create(['name' => 'Bulu']);

        $this->actingAs($owner, 'web')->get('/app/stock')
            ->assertOk()->assertInertia(fn ($page) => $page->where('suppliers.0.count', 0));
    }
}
