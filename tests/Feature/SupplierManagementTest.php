<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockIntake;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Seaman live reports, 2026-11-06: (1) typing an existing supplier's name
 * made a silent twin; (2) there was no way to delete a supplier at all;
 * (3) the product sheet showed 🚚 but nothing could SET it.
 */
class SupplierManagementTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    /** The suppliers routes sit behind feature:suppliers — grant it, or every call 403s silently. */
    private function aSupplierShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');

        return [$shop, $owner];
    }

    public function test_a_same_name_supplier_is_refused_not_twinned(): void
    {
        [$shopA, $ownerA] = $this->aSupplierShop();
        $this->actingAs($ownerA, 'web');

        $this->post(route('app.suppliers.store'), ['name' => 'Jubilee'])->assertSessionHasNoErrors();
        // case-insensitively the same name → a warning, never a second row
        $this->post(route('app.suppliers.store'), ['name' => 'jubilee '])->assertSessionHasErrors('name');
        $this->assertSame(1, Supplier::withoutGlobalScopes()->where('shop_id', $shopA->id)->count());

        // another shop may of course have its own Jubilee
        [, $ownerB] = $this->aSupplierShop();
        $this->actingAs($ownerB, 'web')
            ->post(route('app.suppliers.store'), ['name' => 'Jubilee'])->assertSessionHasNoErrors();
    }

    public function test_delete_works_for_history_free_rows_and_is_refused_for_everything_else(): void
    {
        [$shop, $owner] = $this->aSupplierShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        // clean duplicate: deletable; a product pointing at it falls to Nil
        $dupe = Supplier::create(['name' => 'Jubile (typo)']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shoe', 'cost' => 1, 'price' => 2, 'stock' => 0, 'supplier_id' => $dupe->id]);
        $this->delete(route('app.suppliers.destroy', $dupe->id))->assertSessionHasNoErrors();
        $this->assertNull(Supplier::find($dupe->id));
        $this->assertNull($product->fresh()->supplier); // Nil, the owner's rule

        // payable outstanding → refused
        $owed = Supplier::create(['name' => 'Owed', 'payable' => 500]);
        $this->delete(route('app.suppliers.destroy', $owed->id))->assertSessionHasErrors('supplier');
        $this->assertNotNull(Supplier::find($owed->id));

        // purchase history → refused
        $bought = Supplier::create(['name' => 'Bought']);
        Purchase::create(['shop_id' => $shop->id, 'supplier_id' => $bought->id, 'supplier' => 'Bought', 'memo' => 'x', 'amount' => 100, 'method' => 'cash', 'status' => 'received', 'date' => now()->toDateString()]);
        $this->delete(route('app.suppliers.destroy', $bought->id))->assertSessionHasErrors('supplier');

        // intake-ledger rows → refused
        $intaken = Supplier::create(['name' => 'Intaken']);
        StockIntake::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'qty' => 1, 'unit_cost' => 1, 'total_cost' => 1, 'source' => 'product_stock_in', 'supplier_id' => $intaken->id, 'date' => now()->toDateString()]);
        $this->delete(route('app.suppliers.destroy', $intaken->id))->assertSessionHasErrors('supplier');
    }

    public function test_the_product_sheet_saves_its_default_supplier(): void
    {
        [$shop, $owner] = $this->aSupplierShop();
        $this->actingAs($owner, 'web');
        \App\Support\Tenancy::set($shop->id);

        $supplier = Supplier::create(['name' => 'Jubilee']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boot', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $this->put(route('app.products.update', $product->id), [
            'name' => 'Boot', 'cost' => 1, 'price' => 2, 'stock' => 0, 'supplier_id' => $supplier->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($supplier->id, $product->fresh()->supplier_id);

        // a foreign supplier id quietly becomes Nil, never a cross-tenant pointer
        [$shopB] = $this->createShopWithOwner();
        $foreign = Supplier::withoutGlobalScopes()->create(['shop_id' => $shopB->id, 'name' => 'Foreign']);
        $this->put(route('app.products.update', $product->id), [
            'name' => 'Boot', 'cost' => 1, 'price' => 2, 'stock' => 0, 'supplier_id' => $foreign->id,
        ])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->supplier_id);
    }
}
