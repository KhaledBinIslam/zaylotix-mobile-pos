<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Phase 4 item #2 — "real PO/GRN" (see docs/FIX_PLAN.md's design note). A
 * wholly new, parallel system alongside the existing single-line Purchase
 * flow — placing a PO moves no stock or money; only receive() does.
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function setupShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 10000]);
        $this->grantFeature($shop, 'purchase_orders');
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'ABC Traders']);

        return [$shop, $owner, $supplier];
    }

    public function test_placing_a_multi_line_po_moves_no_stock_or_money(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $oil = Product::create(['shop_id' => $shop->id, 'name' => 'Oil', 'cost' => 100, 'price' => 130, 'stock' => 5]);

        $response = $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $rice->id, 'qty' => 100, 'cost' => 45],
                ['product_id' => $oil->id, 'qty' => 20, 'cost' => 95],
            ],
        ]);

        $po = PurchaseOrder::first();
        $response->assertRedirect(route('app.purchaseOrders.show', $po->id));
        $this->assertSame('ordered', $po->status);
        $this->assertEquals(100 * 45 + 20 * 95, (float) $po->subtotal);
        $this->assertStringStartsWith('PO-', $po->po_number);
        $this->assertSame(2, $po->items()->count());

        // nothing moved yet
        $this->assertEquals(10, (float) $rice->fresh()->stock);
        $this->assertEquals(5, (float) $oil->fresh()->stock);
        $this->assertEquals(10000, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(0, (float) $supplier->fresh()->payable);
    }

    public function test_partial_receipt_updates_stock_and_payable_proportionally_and_leaves_it_partially_received(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $rice->id, 'qty' => 100, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::first();
        $item = $po->items()->first();

        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.receive', $po->id), [
            'method' => 'credit',
            'items' => [['id' => $item->id, 'qty' => 60]],
        ])->assertRedirect();

        $this->assertEquals(70, (float) $rice->fresh()->stock); // 10 + 60
        $this->assertEquals(60 * 45, (float) $supplier->fresh()->payable);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertEquals(60, (float) $item->fresh()->qty_received);
    }

    public function test_receiving_the_rest_marks_the_po_fully_received(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $rice->id, 'qty' => 100, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::first();
        $item = $po->items()->first();

        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.receive', $po->id), ['method' => 'credit', 'items' => [['id' => $item->id, 'qty' => 60]]]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.receive', $po->id), ['method' => 'credit', 'items' => [['id' => $item->id, 'qty' => 40]]])->assertRedirect();

        $this->assertEquals(110, (float) $rice->fresh()->stock); // 10 + 60 + 40
        $this->assertSame('received', $po->fresh()->status);
        $this->assertEquals(100 * 45, (float) $supplier->fresh()->payable);
    }

    public function test_cannot_receive_more_than_what_remains_on_a_line(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $rice->id, 'qty' => 100, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::first();
        $item = $po->items()->first();

        $this->actingAs($owner, 'web')->postJson(route('app.purchaseOrders.receive', $po->id), [
            'method' => 'credit', 'items' => [['id' => $item->id, 'qty' => 150]],
        ])->assertStatus(422);

        $this->assertEquals(10, (float) $rice->fresh()->stock); // untouched
    }

    public function test_cash_receipt_decrements_cash_balance_instead_of_supplier_payable(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $rice->id, 'qty' => 10, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::first();
        $item = $po->items()->first();

        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.receive', $po->id), [
            'method' => 'cash', 'items' => [['id' => $item->id, 'qty' => 10]],
        ])->assertRedirect();

        $this->assertEquals(10000 - 450, (float) $shop->fresh()->cash_balance);
        $this->assertEquals(0, (float) $supplier->fresh()->payable);
    }

    public function test_a_variant_line_receives_onto_the_correct_variant(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $this->grantFeature($shop, 'product_variants');
        $shirt = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 300, 'price' => 500, 'stock' => 10]);
        $variant = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $shirt->id, 'size' => 'XL', 'color' => 'Blue', 'stock' => 4]);

        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $shirt->id, 'product_variant_id' => $variant->id, 'qty' => 20, 'cost' => 280]],
        ]);
        $po = PurchaseOrder::first();
        $item = $po->items()->first();

        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.receive', $po->id), [
            'method' => 'credit', 'items' => [['id' => $item->id, 'qty' => 20]],
        ])->assertRedirect();

        $this->assertEquals(24, (float) $variant->fresh()->stock); // 4 + 20
        $this->assertEquals(30, (float) $shirt->fresh()->stock); // 10 + 20
    }

    public function test_cancelling_an_untouched_po_marks_it_cancelled(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $rice->id, 'qty' => 10, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::first();

        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.cancel', $po->id))->assertRedirect();

        $this->assertSame('cancelled', $po->fresh()->status);
    }

    public function test_cannot_cancel_a_po_that_already_received_something(): void
    {
        [$shop, $owner, $supplier] = $this->setupShop();
        $rice = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $rice->id, 'qty' => 10, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::first();
        $item = $po->items()->first();
        $this->actingAs($owner, 'web')->post(route('app.purchaseOrders.receive', $po->id), ['method' => 'credit', 'items' => [['id' => $item->id, 'qty' => 5]]]);

        $this->actingAs($owner, 'web')->postJson(route('app.purchaseOrders.cancel', $po->id))->assertStatus(422);

        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_purchase_orders_are_tenant_scoped(): void
    {
        [$shopA, $ownerA, $supplierA] = $this->setupShop();
        [$shopB, $ownerB] = $this->createShopWithOwner();
        $this->grantFeature($shopB, 'purchase_orders');
        $riceA = Product::create(['shop_id' => $shopA->id, 'name' => 'Rice', 'cost' => 50, 'price' => 70, 'stock' => 10]);
        $this->actingAs($ownerA, 'web')->post(route('app.purchaseOrders.store'), [
            'supplier_id' => $supplierA->id,
            'items' => [['product_id' => $riceA->id, 'qty' => 10, 'cost' => 45]],
        ]);
        $po = PurchaseOrder::withoutGlobalScopes()->first();

        $this->actingAs($ownerB, 'web')->get(route('app.purchaseOrders.show', $po->id))->assertNotFound();
    }

    public function test_no_purchase_order_route_reachable_without_the_feature_granted(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(); // feature not granted
        Supplier::create(['shop_id' => $shop->id, 'name' => 'ABC Traders']);

        $this->actingAs($owner, 'web')->get(route('app.purchaseOrders.index'))->assertForbidden();
    }
}
