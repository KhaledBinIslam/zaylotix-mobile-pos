<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 47 (Baby Park) — derived supplier intake/sales stats and the
 * owner-only manual due. The design contract under test: article entry
 * alone (products.supplier_id + cost) is enough for the supplier pages to
 * show what came in and what sells, with NO purchase entry; money owed is
 * only ever created by the owner's explicit "বাকি যোগ করুন".
 */
class SupplierIntakeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function makeSale($shop, Product $product, float $qty, float $price, float $cost): void
    {
        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-'.random_int(1000, 999999),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => $price * $qty, 'discount' => 0, 'service_charge' => 0, 'vat' => 0,
            'total' => $price * $qty, 'profit' => ($price - $cost) * $qty, 'payment_mode' => 'cash',
        ]);
        SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'qty' => $qty, 'price' => $price, 'discount' => 0, 'cost' => $cost,
        ]);
    }

    public function test_index_shows_derived_intake_and_sales_stats_per_supplier(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'SMS']);
        $product = Product::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'name' => 'L-10001', 'cost' => 1000, 'price' => 1990, 'stock' => 8]);
        $this->makeSale($shop, $product, 2, 1990, 1000);

        $this->actingAs($owner, 'web')->get('/app/suppliers')->assertOk()
            ->assertInertia(function ($page) use ($supplier) {
                $row = collect($page->toArray()['props']['suppliers'])->firstWhere('id', $supplier->id);
                $this->assertSame(1, $row['stats']['products']);
                $this->assertEquals(8000.0, $row['stats']['stock_value']); // 1000 × 8 in stock
                $this->assertEquals(2.0, $row['stats']['sold_units']);
                $this->assertEquals(3980.0, $row['stats']['sold_revenue']); // 1990 × 2
                $this->assertEquals(10000.0, $row['stats']['intake_value']); // cost × (stock 8 + sold 2)
            });
    }

    public function test_show_lists_the_suppliers_products_with_lifetime_sold_figures(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'SMS']);
        $product = Product::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'name' => 'L-10002', 'cost' => 500, 'price' => 900, 'stock' => 3]);
        Product::create(['shop_id' => $shop->id, 'name' => 'Unlinked', 'cost' => 1, 'price' => 2, 'stock' => 1]); // no supplier — must not appear
        $this->makeSale($shop, $product, 1, 900, 500);

        $this->actingAs($owner, 'web')->get('/app/suppliers/'.$supplier->id)->assertOk()
            ->assertInertia(function ($page) {
                $products = $page->toArray()['props']['products'];
                $this->assertCount(1, $products);
                $this->assertSame('L-10002', $products[0]['name']);
                $this->assertEquals(1.0, $products[0]['sold_units']);
                $this->assertEquals(900.0, $products[0]['sold_revenue']);
                $this->assertEquals(2000.0, $products[0]['intake_value']); // 500 × (3 + 1)
            });
    }

    public function test_owner_can_add_a_manual_due_recorded_as_a_credit_purchase(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'SMS', 'payable' => 0]);

        $this->actingAs($owner, 'web')
            ->post('/app/suppliers/'.$supplier->id.'/due', ['amount' => 2500, 'memo' => 'অক্টোবরের চালান'])
            ->assertRedirect();

        $this->assertEquals(2500.0, (float) $supplier->fresh()->payable);
        $purchase = Purchase::where('supplier_id', $supplier->id)->first();
        $this->assertNotNull($purchase);
        $this->assertSame('credit', $purchase->method);
        $this->assertSame('received', $purchase->status);
        $this->assertEquals(2500.0, (float) $purchase->amount);
        $this->assertSame('অক্টোবরের চালান', $purchase->memo);
    }

    public function test_staff_cannot_add_a_manual_due(): void
    {
        [$shop] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $staff = User::create(['shop_id' => $shop->id, 'name' => 'Staff', 'phone' => '01900012345', 'password' => 'secret1', 'role' => 'staff', 'permissions' => ['purchases'], 'lang' => 'bn']);
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'SMS', 'payable' => 0]);

        $this->actingAs($staff, 'web')
            ->post('/app/suppliers/'.$supplier->id.'/due', ['amount' => 100, 'memo' => 'x'])
            ->assertForbidden();

        $this->assertEquals(0.0, (float) $supplier->fresh()->payable);
    }

    public function test_article_entry_alone_needs_no_purchase_row_for_stats(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'SMS']);
        Product::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'name' => 'L-10003', 'cost' => 700, 'price' => 1200, 'stock' => 5]);

        $this->assertSame(0, Purchase::count()); // nothing was manually entered

        $this->actingAs($owner, 'web')->get('/app/suppliers')->assertOk()
            ->assertInertia(function ($page) use ($supplier) {
                $row = collect($page->toArray()['props']['suppliers'])->firstWhere('id', $supplier->id);
                $this->assertEquals(3500.0, $row['stats']['intake_value']); // stats exist anyway
            });
    }
}
