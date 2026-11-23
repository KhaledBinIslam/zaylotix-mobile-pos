<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park live (2026-11-23): "cannot delete a product", nothing in
 * laravel.log. Deleting is a SOFT delete through the real route, whatever
 * hangs off the product — sales history, a pooled sibling, sizes with
 * barcodes, serials, batches — and when it genuinely fails it must say so
 * (a Bengali error back to the sheet) and log who/what failed.
 */
class ProductDeleteRobustnessTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function deleteVia($owner, Product $product)
    {
        return $this->actingAs($owner, 'web')->delete(route('app.products.destroy', $product->id));
    }

    public function test_a_product_with_sales_history_soft_deletes_and_keeps_its_sale_items(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sold Shoe', 'cost' => 500, 'price' => 900, 'stock' => 3]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Walk-in', 'phone' => '0100', 'due' => 0]);
        $sale = Sale::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_no' => 'INV-DEL-1',
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 900, 'discount' => 0, 'service_charge' => 0, 'vat' => 0, 'total' => 900, 'profit' => 400,
            'payment_mode' => 'cash',
        ]);
        $item = SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'unit_factor' => 1, 'qty' => 1, 'price' => 900, 'discount' => 0, 'cost' => 500,
        ]);

        $this->deleteVia($owner, $product)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertNotNull(Product::withoutGlobalScopes()->find($product->id)->deleted_at);
        $this->assertDatabaseHas('sale_items', ['id' => $item->id, 'product_id' => $product->id]); // history intact
    }

    public function test_a_pooled_products_branch_copy_and_master_soft_delete_from_either_side(): void
    {
        [$main, $mainOwner] = $this->createShopWithOwner();
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');
        [$branch, $branchOwner] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);

        // the created() hooks put a linked copy in the branch
        $master = Product::create(['shop_id' => $main->id, 'name' => 'Pooled', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $copy = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $master->id)->firstOrFail();

        // branch -> master
        $this->deleteVia($branchOwner, $copy)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(Product::withoutGlobalScopes()->find($copy->id)->deleted_at);
        $this->assertNotNull(Product::withoutGlobalScopes()->find($master->id)->deleted_at, 'the cascade must still reach the master');

        // master -> branch, on a second pair
        $master2 = Product::create(['shop_id' => $main->id, 'name' => 'Pooled 2', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $copy2 = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $master2->id)->firstOrFail();
        $this->deleteVia($mainOwner, $master2)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(Product::withoutGlobalScopes()->find($copy2->id)->deleted_at);
    }

    public function test_a_product_with_variants_soft_deletes_them_too_and_releases_their_barcodes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'product_variants');
        Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Sized', 'cost' => 100, 'price' => 200, 'stock' => 3]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '40', 'color' => 'Black', 'barcode' => 'SZ-40-BLK', 'stock' => 3]);

        $this->deleteVia($owner, $product)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(Product::withoutGlobalScopes()->find($product->id)->deleted_at);
        $row = ProductVariant::withoutGlobalScopes()->find($v->id);
        $this->assertNotNull($row->deleted_at, 'sizes go with the article');
        $this->assertNull($row->barcode, 'a deleted size hands its barcode back');
    }

    public function test_a_serial_and_batch_tracked_product_soft_deletes_without_touching_its_rows(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Phone', 'cost' => 10000, 'price' => 15000, 'stock' => 2, 'track_serial' => true]);
        $serial = ProductSerial::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'imei' => '356938035643809', 'cost' => 10000, 'status' => 'in_stock']);
        $batch = ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'B-1', 'expiry_date' => now()->addYear()->toDateString(), 'qty' => 2, 'cost' => 10000]);

        $this->deleteVia($owner, $product)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(Product::withoutGlobalScopes()->find($product->id)->deleted_at);
        $this->assertDatabaseHas('product_serials', ['id' => $serial->id]);
        $this->assertDatabaseHas('product_batches', ['id' => $batch->id]);
    }

    /** When the delete genuinely blows up, the owner gets a Bengali error and laravel.log gets the who/what — never a silent no-op or a 500. */
    public function test_a_failing_delete_returns_a_bengali_error_and_logs_the_product(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Cursed', 'cost' => 1, 'price' => 2, 'stock' => 1]);

        Product::deleting(function () {
            throw new \RuntimeException('simulated cascade failure');
        });
        Log::spy();

        $response = $this->deleteVia($owner, $product);

        $response->assertRedirect()->assertSessionHasErrors('delete');
        $this->assertStringContainsString('পণ্যটি মুছা যায়নি', session('errors')->first('delete'));
        $this->assertStringContainsString("#{$product->id}", session('errors')->first('delete'));
        $this->assertNull(Product::withoutGlobalScopes()->find($product->id)->deleted_at, 'rolled back, not half-deleted');
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => $msg === 'product.delete failed'
            && $ctx['product_id'] === $product->id && $ctx['shop_id'] === $shop->id
            && str_contains($ctx['message'], 'simulated cascade failure'))->once();
    }
}
