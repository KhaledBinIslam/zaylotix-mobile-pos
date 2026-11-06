<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * variants:merge-case-duplicates — the one-time repair for Seaman's live
 * INV-1001 report: 'Navy blue' and 'Navy Blue' XXL were two separate rows,
 * one sold from, one untouched ("sell korle stock kombe na").
 */
class MergeCaseDuplicateVariantsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit with Radium', 'cost' => 650, 'price' => 1190, 'stock' => 0]);
        $sold = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Navy blue', 'size' => 'XXL', 'stock' => 2]);
        $untouched = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Navy Blue', 'size' => 'XXL', 'stock' => 4]);

        $this->artisan('variants:merge-case-duplicates', ['--shop' => $shop->id])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertEquals(2, $sold->fresh()->stock);
        $this->assertEquals(4, $untouched->fresh()->stock);
        $this->assertNotNull(ProductVariant::find($untouched->id)); // still alive, nothing deleted
    }

    public function test_confirm_merges_onto_the_variant_with_sale_history_and_sums_stock(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit with Radium', 'cost' => 650, 'price' => 1190, 'stock' => 0]);
        // this one was actually sold from (the real, in-use row)
        $sold = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Navy blue', 'size' => 'XXL', 'stock' => 2, 'barcode' => 'ZV-SOLD']);
        $untouched = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Navy Blue', 'size' => 'XXL', 'stock' => 4, 'barcode' => 'ZV-UNTOUCHED']);

        $sale = Sale::create(['shop_id' => $shop->id, 'invoice_no' => 'INV-1001', 'date' => now()->toDateString(), 'time' => '19:06', 'subtotal' => 5160, 'discount' => 1760, 'vat' => 0, 'total' => 3400, 'profit' => 0, 'payment_mode' => 'cash', 'counted_in_reports' => true]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_variant_id' => $sold->id, 'product_name' => 'Boiler Suit with Radium', 'qty' => 4, 'price' => 1290, 'discount' => 0, 'cost' => 650]);

        $this->artisan('variants:merge-case-duplicates', ['--shop' => $shop->id, '--confirm' => true])
            ->assertSuccessful();

        // the sold-from row survives, now holding BOTH stocks
        $this->assertEquals(6, $sold->fresh()->stock);
        // the untouched twin is gone, its barcode released (not reused elsewhere, just freed)
        $this->assertNull(ProductVariant::find($untouched->id));
        $this->assertNull(ProductVariant::withTrashed()->find($untouched->id)->barcode);
        // the sale's own history still points at exactly the row it always did
        $this->assertSame($sold->id, SaleItem::where('sale_id', $sale->id)->sole()->product_variant_id);

        // idempotent — running it again finds nothing left to merge
        $this->artisan('variants:merge-case-duplicates', ['--shop' => $shop->id, '--confirm' => true])
            ->expectsOutputToContain('কোনো case-duplicate')
            ->assertSuccessful();
    }

    public function test_without_sale_history_the_oldest_row_survives(): void
    {
        [$shop] = $this->createShopWithOwner();
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'cost' => 100, 'price' => 200, 'stock' => 0]);
        $first = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'grey', 'stock' => 3]);
        $second = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'GREY', 'stock' => 5]);

        $this->artisan('variants:merge-case-duplicates', ['--shop' => $shop->id, '--confirm' => true])->assertSuccessful();

        $this->assertEquals(8, $first->fresh()->stock);
        $this->assertNull(ProductVariant::find($second->id));
    }
}
