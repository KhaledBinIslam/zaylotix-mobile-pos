<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled live (Seaman): "alda supllier dia product tulchi but aseni track
 * hoini ... full system ager moto tekhe geche". The grid and the 🚚 sheet
 * wrote lines but the quick "+" button, the CSV bulk sheet, purchases, PO
 * receipts and every create-with-stock path did not — so stock kept
 * landing with no supplier attached and the filter/search stayed blind to
 * it. Every intake path must write/merge a line on a source_lines shop.
 */
class SourceLineEverywhereTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function lineShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants', 'purchases'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        return [$shop, $owner];
    }

    public function test_variant_quick_plus_stock_in_writes_a_line(): void
    {
        [$shop] = $this->lineShop();
        $sujon = Supplier::create(['name' => 'Sujon']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'color' => 'Orange', 'stock' => 0]);

        $this->post(route('app.productVariants.stockIn', $v->id), [
            'qty' => 3, 'cost' => 700, 'supplier_id' => $sujon->id, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_variant_id', $v->id)->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($line, 'quick "+" stock-in must create a Sujon line');
        $this->assertEquals(3.0, (float) $line->stock);
        $this->assertEquals(700.0, (float) $line->cost);
        $this->assertSame('ZL'.$line->id, $line->barcode);
        $this->assertEquals(3.0, (float) $v->fresh()->stock); // mirror unchanged in meaning
        $this->assertEquals(3 * 700, (float) $sujon->fresh()->payable);
    }

    public function test_plain_product_stock_in_writes_a_line(): void
    {
        [$shop] = $this->lineShop();
        $sujon = Supplier::create(['name' => 'Sujon']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Flag', 'cost' => 100, 'price' => 150, 'stock' => 0]);

        $this->post(route('app.products.stockIn', $p->id), [
            'qty' => 5, 'cost' => 110, 'supplier_id' => $sujon->id,
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_id', $p->id)->whereNull('product_variant_id')->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($line);
        $this->assertEquals(5.0, (float) $line->stock);
        // mirror cost rides the line's cost (overwrite, no blend)
        $this->assertEquals(110.0, (float) $p->fresh()->cost);
    }

    public function test_purchase_with_product_writes_a_line(): void
    {
        [$shop] = $this->lineShop();
        $sujon = Supplier::create(['name' => 'Sujon']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Cap', 'cost' => 80, 'price' => 120, 'stock' => 0]);

        $this->post(route('app.purchases.store'), [
            'amount' => 450, 'method' => 'credit', 'supplier_id' => $sujon->id,
            'product_id' => $p->id, 'qty' => 5, 'cost' => 90, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_id', $p->id)->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($line, 'a received purchase must write a line');
        $this->assertEquals(5.0, (float) $line->stock);
    }

    public function test_purchase_order_receive_writes_a_line(): void
    {
        [$shop, $owner] = $this->lineShop();
        $this->grantFeature($shop, 'purchase_orders');
        $sujon = Supplier::create(['name' => 'Sujon']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Belt', 'cost' => 50, 'price' => 90, 'stock' => 0]);
        $po = PurchaseOrder::create(['shop_id' => $shop->id, 'supplier_id' => $sujon->id, 'po_number' => 'PO-9', 'date' => now()->toDateString(), 'status' => 'ordered', 'subtotal' => 500, 'user_id' => $owner->id]);
        $item = PurchaseOrderItem::create(['shop_id' => $shop->id, 'purchase_order_id' => $po->id, 'product_id' => $p->id, 'product_name' => 'Belt', 'qty_ordered' => 10, 'qty_received' => 0, 'cost' => 50]);

        $this->post(route('app.purchaseOrders.receive', $po->id), [
            'method' => 'credit',
            'items' => [['id' => $item->id, 'qty' => 10]],
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_id', $p->id)->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($line, 'a received PO must write a line');
        $this->assertEquals(10.0, (float) $line->stock);
    }

    public function test_product_created_with_stock_and_default_supplier_writes_a_line(): void
    {
        [$shop] = $this->lineShop();
        $bulu = Supplier::create(['name' => 'Bulu']);

        $this->post(route('app.products.store'), [
            'name' => 'Gloves', 'cost' => 60, 'price' => 100, 'stock' => 12, 'supplier_id' => $bulu->id,
        ])->assertSessionHasNoErrors();

        $p = Product::where('name', 'Gloves')->first();
        $line = ProductSource::where('product_id', $p->id)->where('supplier_id', $bulu->id)->first();
        $this->assertNotNull($line, 'a product born with stock must be filed under its default supplier');
        $this->assertEquals(12.0, (float) $line->stock);
    }

    public function test_bulk_grid_new_variants_file_under_the_picked_supplier_and_track_payable(): void
    {
        [$shop] = $this->lineShop();
        $bulu = Supplier::create(['name' => 'Bulu']);
        $sujon = Supplier::create(['name' => 'Sujon 3rd party']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Life Jacket', 'cost' => 900, 'price' => 1500, 'stock' => 0, 'supplier_id' => $bulu->id]);

        $this->post(route('app.productVariants.bulkStore', $p->id), [
            'variants' => [
                ['color' => 'Orange', 'size' => 'M', 'stock' => 4],
                ['color' => 'Orange', 'size' => 'L', 'stock' => 2],
            ],
            'cost' => 950, 'price' => 1600,
            'supplier_id' => $sujon->id, 'payment_method' => 'credit',
        ])->assertSessionHasNoErrors();

        // the PICKED supplier wins over the product's default
        $this->assertSame(2, ProductSource::where('product_id', $p->id)->where('supplier_id', $sujon->id)->count());
        $this->assertSame(0, ProductSource::where('product_id', $p->id)->where('supplier_id', $bulu->id)->count());
        $this->assertEquals(6 * 950, (float) $sujon->fresh()->payable);
    }

    public function test_csv_bulk_opening_stock_writes_lines(): void
    {
        [$shop] = $this->lineShop();
        $this->grantFeature($shop, 'opening_stock');
        $sujon = Supplier::create(['name' => 'Sujon']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Epaulette', 'cost' => 200, 'price' => 350, 'stock' => 0]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => '1 Bar', 'stock' => 0]);

        $csv = "product_id,variant_id,product,colour,size,current_stock,new_qty,cost\n{$p->id},{$v->id},Epaulette,,1 Bar,0,7,210\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('sheet.csv', $csv);

        $this->post(route('app.openingStock.upload'), [
            'file' => $file, 'supplier_id' => $sujon->id, 'dry_run' => 0,
        ])->assertOk();

        $line = ProductSource::where('product_variant_id', $v->id)->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($line, 'CSV bulk stock must write a line');
        $this->assertEquals(7.0, (float) $line->stock);
    }

    public function test_supplier_return_decrements_that_suppliers_line_first(): void
    {
        [$shop] = $this->lineShop();
        $sujon = Supplier::create(['name' => 'Sujon']);
        $bulu = Supplier::create(['name' => 'Bulu']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Whistle', 'cost' => 30, 'price' => 60, 'stock' => 10]);
        $sujonLine = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $sujon->id, 'cost' => 30, 'price' => 60, 'stock' => 6]);
        $buluLine = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $bulu->id, 'cost' => 30, 'price' => 60, 'stock' => 4]);

        $this->post(route('app.supplierReturns.store'), [
            'supplier_id' => $sujon->id, 'product_id' => $p->id, 'qty' => 4,
            'settlement_method' => 'payable', 'amount' => 120,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(2.0, (float) $sujonLine->fresh()->stock); // his lot shrank
        $this->assertEquals(4.0, (float) $buluLine->fresh()->stock); // the other untouched
        $this->assertEquals(6.0, (float) $p->fresh()->stock);
    }
}
