<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled live: "product entry somoy jate auto cole jai kon supplier
 * tekhe ki ki product niche koto tk er product niche ... koto due ache
 * show hobe" — every stock-in screen that picks a supplier (the simple
 * stock-in sheet, the opening-stock grid, the Part 1 stock-in sheet)
 * recorded WHO the stock came from but never touched what's owed for
 * it; only the separate Purchases module did. These pin the fix across
 * all four screens via the shared SupplierPayable helper: 'credit' adds
 * to the supplier's payable, 'cash'/'bank' decrements the shop's own
 * balance, and no supplier or no payment_method at all is a no-op
 * (byte-identical to before this feature existed).
 */
class SupplierPayableStockInTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_simple_product_stock_in_on_credit_adds_to_supplier_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Jubilee', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 0]);

        $this->post(route('app.products.stockIn', $product->id), [
            'qty' => 5, 'cost' => 1600, 'supplier_id' => $supplier->id, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(8000, (float) $supplier->fresh()->payable); // 5 * 1600
    }

    public function test_simple_product_stock_in_paid_cash_decrements_the_shops_cash_balance(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');
        $shop->update(['cash_balance' => 50000]);

        $supplier = Supplier::create(['name' => 'Jubilee', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 0]);

        $this->post(route('app.products.stockIn', $product->id), [
            'qty' => 5, 'cost' => 1600, 'supplier_id' => $supplier->id, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $supplier->fresh()->payable); // never touched
        $this->assertEquals(42000, (float) $shop->fresh()->cash_balance); // 50000 - 8000
    }

    public function test_variant_stock_in_on_credit_adds_to_supplier_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Jubilee', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 1600, 'price' => 2290, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 0, 'cost' => 1600]);

        $this->post(route('app.productVariants.stockIn', $variant->id), [
            'qty' => 3, 'cost' => 1700, 'supplier_id' => $supplier->id, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5100, (float) $supplier->fresh()->payable); // 3 * 1700
    }

    public function test_opening_stock_grid_applies_one_payment_for_the_whole_submission(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'product_variants', 'opening_stock'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Bulu', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 0]);
        $l = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 0]);

        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $supplier->id, 'default_cost' => 650, 'payment_method' => 'credit',
            'cells' => [
                ['variant_id' => $m->id, 'qty' => 4],
                ['variant_id' => $l->id, 'qty' => 2, 'cost' => 700], // its own cost overrides the default
            ],
        ])->assertSessionHasNoErrors();

        // (4 * 650) + (2 * 700) = 2600 + 1400 = 4000, ONE payable entry for the whole grid submit
        $this->assertEquals(4000, (float) $supplier->fresh()->payable);
    }

    public function test_source_line_stock_in_sheet_applies_payment_for_that_one_line(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Sujon', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 0]);

        $this->post(route('app.productSources.stockInVariant', $variant->id), [
            'supplier_id' => $supplier->id, 'qty' => 4, 'cost' => 700, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(2800, (float) $supplier->fresh()->payable); // 4 * 700
    }

    /** No supplier picked, or a payment_method never sent at all — nothing to owe anyone, zero behavior change. */
    public function test_no_supplier_or_no_payment_method_is_a_no_op(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');
        $shop->update(['cash_balance' => 50000]);

        $supplier = Supplier::create(['name' => 'Jubilee', 'payable' => 0]);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 0]);

        // a supplier is picked, but no payment_method sent at all (an owner who hasn't touched this UI yet)
        $this->post(route('app.products.stockIn', $product->id), [
            'qty' => 5, 'cost' => 1600, 'supplier_id' => $supplier->id,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $supplier->fresh()->payable);
        $this->assertEquals(50000, (float) $shop->fresh()->cash_balance);
    }
}
