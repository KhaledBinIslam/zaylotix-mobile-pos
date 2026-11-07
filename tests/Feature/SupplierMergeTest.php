<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSource;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\StockIntake;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled live: "Sujon" and "Sujon 3rd party" — two real, separate rows
 * that turned out to be the same supplier. Merge repoints every table
 * that names a supplier to the survivor BEFORE deleting the loser —
 * mandatory for supplier_payments and purchase_orders specifically,
 * since those CASCADE on delete and would otherwise take real payment/
 * order history down with the loser.
 */
class SupplierMergeTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_merge_repoints_every_referencing_table_and_sums_payable(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants', 'batch_tracking'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $loser = Supplier::create(['name' => 'Sujon', 'payable' => 500]);
        $survivor = Supplier::create(['name' => 'Sujon 3rd party', 'payable' => 200]);

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 1, 'supplier_id' => $loser->id]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 1]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'supplier_id' => $loser->id, 'cost' => 650, 'price' => 1090, 'stock' => 1]);
        $purchase = Purchase::create(['shop_id' => $shop->id, 'supplier_id' => $loser->id, 'amount' => 650, 'method' => 'credit', 'status' => 'received', 'date' => now()->toDateString()]);
        $return = SupplierReturn::create(['shop_id' => $shop->id, 'supplier_id' => $loser->id, 'product_id' => $product->id, 'qty' => 1, 'amount' => 650, 'settlement_method' => 'payable', 'reason' => 'damaged', 'date' => now()->toDateString()]);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'supplier_id' => $loser->id, 'qty' => 1, 'remaining_qty' => 1, 'cost' => 650]);
        $intake = StockIntake::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'supplier_id' => $loser->id, 'qty' => 1, 'source' => 'opening_stock']);
        $payment = SupplierPayment::create(['shop_id' => $shop->id, 'supplier_id' => $loser->id, 'user_id' => $owner->id, 'amount' => 100, 'payable_before' => 600, 'payable_after' => 500, 'method' => 'cash', 'date' => now()->toDateString()]);
        $po = PurchaseOrder::create(['shop_id' => $shop->id, 'supplier_id' => $loser->id, 'po_number' => 'PO-1', 'date' => now()->toDateString(), 'status' => 'draft', 'subtotal' => 650, 'user_id' => $owner->id]);

        $this->post(route('app.suppliers.merge', $loser->id), ['into' => $survivor->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($survivor->id, $product->fresh()->supplier_id);
        $this->assertSame($survivor->id, $line->fresh()->supplier_id);
        $this->assertSame($survivor->id, $purchase->fresh()->supplier_id);
        $this->assertSame($survivor->id, $return->fresh()->supplier_id);
        $this->assertSame($survivor->id, $batch->fresh()->supplier_id);
        $this->assertSame($survivor->id, $intake->fresh()->supplier_id);
        // these two CASCADE on delete — if they weren't repointed, the
        // fresh() calls below would come back null (rows gone)
        $this->assertSame($survivor->id, $payment->fresh()->supplier_id);
        $this->assertSame($survivor->id, $po->fresh()->supplier_id);

        $this->assertEquals(700, (float) $survivor->fresh()->payable); // 500 + 200, summed not dropped
        $this->assertNull(Supplier::find($loser->id)); // the loser is gone
    }

    public function test_merging_into_itself_or_a_nonexistent_supplier_is_refused(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Sujon']);

        $this->post(route('app.suppliers.merge', $supplier->id), ['into' => $supplier->id])
            ->assertSessionHasErrors('into');
        $this->post(route('app.suppliers.merge', $supplier->id), ['into' => 999999])
            ->assertSessionHasErrors('into');

        $this->assertNotNull(Supplier::find($supplier->id)); // never deleted on a refused merge
    }

    public function test_merge_is_tenant_scoped(): void
    {
        [$shopA, $ownerA] = $this->createShopWithOwner();
        $this->grantFeature($shopA, 'suppliers');
        [$shopB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'suppliers');

        \App\Support\Tenancy::set($shopA->id);
        $mine = Supplier::create(['name' => 'Mine']);
        \App\Support\Tenancy::set($shopB->id);
        $theirs = Supplier::create(['name' => 'Theirs']);

        \App\Support\Tenancy::set($shopA->id);
        $this->actingAs($ownerA, 'web')
            ->post(route('app.suppliers.merge', $mine->id), ['into' => $theirs->id])
            ->assertSessionHasErrors('into'); // a foreign shop's supplier is simply not found

        $this->assertNotNull(Supplier::find($mine->id));
    }
}
