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
 * Khaled live: "pore kono ta stock tulte vul hole ... tik [kora]" — the
 * two correction tools. MOVE re-files a wrongly-tagged lot's qty under
 * the right supplier(+brand); CLAIM files stock that predates line
 * tracking (mirror > sum of lines) under one. Neither is a stock-in:
 * total stock, payable and the ledger must not move.
 */
class SourceLineCorrectionTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function shopWithLine(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['suppliers', 'source_lines', 'product_variants'] as $f) {
            $this->grantFeature($shop, $f);
        }
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');

        $bulu = Supplier::create(['name' => 'Bulu', 'payable' => 0]);
        $sujon = Supplier::create(['name' => 'Sujon', 'payable' => 0]);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'size' => 'M', 'color' => 'Orange', 'stock' => 7]);
        $buluLine = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $bulu->id, 'cost' => 650, 'price' => 1090, 'stock' => 6]);

        return [$shop, $p, $v, $bulu, $sujon, $buluLine];
    }

    public function test_move_refiles_qty_under_the_right_supplier_without_touching_totals(): void
    {
        [, $p, $v, $bulu, $sujon, $buluLine] = $this->shopWithLine();

        $this->post(route('app.productSources.move', $buluLine->id), [
            'supplier_id' => $sujon->id, 'qty' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(4.0, (float) $buluLine->fresh()->stock);
        $sujonLine = ProductSource::where('product_variant_id', $v->id)->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($sujonLine);
        $this->assertEquals(2.0, (float) $sujonLine->stock);
        $this->assertEquals(650.0, (float) $sujonLine->cost); // carries the lot's own cost
        $this->assertSame('ZL'.$sujonLine->id, $sujonLine->barcode);

        // totals/money untouched — this was a re-filing, not a stock-in
        $this->assertEquals(7.0, (float) $v->fresh()->stock);
        $this->assertEquals(0.0, (float) $sujon->fresh()->payable);
        $this->assertEquals(0.0, (float) $bulu->fresh()->payable);
        $this->assertSame(0, \App\Models\StockIntake::count());
    }

    public function test_move_refuses_more_than_the_line_holds_and_same_destination(): void
    {
        [, , , $bulu, $sujon, $buluLine] = $this->shopWithLine();

        $this->post(route('app.productSources.move', $buluLine->id), [
            'supplier_id' => $sujon->id, 'qty' => 99,
        ])->assertSessionHasErrors('qty');

        $this->post(route('app.productSources.move', $buluLine->id), [
            'supplier_id' => $bulu->id, 'qty' => 1,
        ])->assertSessionHasErrors('supplier_id');

        $this->assertEquals(6.0, (float) $buluLine->fresh()->stock); // nothing moved
    }

    public function test_move_merges_into_an_existing_destination_line(): void
    {
        [$shop, $p, $v, , $sujon, $buluLine] = $this->shopWithLine();
        $existing = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $sujon->id, 'cost' => 700, 'price' => 1200, 'stock' => 2]);

        $this->post(route('app.productSources.move', $buluLine->id), [
            'supplier_id' => $sujon->id, 'qty' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(4.0, (float) $existing->fresh()->stock); // 2 + 2, merged
        // weighted cost: (2*700 + 2*650) / 4 = 675
        $this->assertEquals(675.0, (float) $existing->fresh()->cost);
        $this->assertEquals(1200.0, (float) $existing->fresh()->price); // its price kept
        $this->assertSame(1, ProductSource::where('product_variant_id', $v->id)->where('supplier_id', $sujon->id)->count());
    }

    public function test_claim_files_untracked_variant_stock_without_touching_mirror_or_payable(): void
    {
        [, , $v, , $sujon] = $this->shopWithLine(); // mirror 7, lines 6 → 1 untracked

        $this->post(route('app.productSources.claimVariant', $v->id), [
            'supplier_id' => $sujon->id, 'qty' => 1, 'cost' => 700,
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_variant_id', $v->id)->where('supplier_id', $sujon->id)->first();
        $this->assertNotNull($line);
        $this->assertEquals(1.0, (float) $line->stock);
        $this->assertEquals(700.0, (float) $line->cost);
        $this->assertEquals(7.0, (float) $v->fresh()->stock); // mirror already counted it
        $this->assertEquals(0.0, (float) $sujon->fresh()->payable); // nothing was bought today
    }

    public function test_claim_refuses_more_than_the_untracked_remainder(): void
    {
        [, , $v, , $sujon] = $this->shopWithLine(); // only 1 untracked

        $this->post(route('app.productSources.claimVariant', $v->id), [
            'supplier_id' => $sujon->id, 'qty' => 3,
        ])->assertSessionHasErrors('qty');

        $this->assertNull(ProductSource::where('product_variant_id', $v->id)->where('supplier_id', $sujon->id)->first());
    }

    public function test_claim_works_for_a_plain_product_too(): void
    {
        [$shop, , , , $sujon] = $this->shopWithLine();
        $plain = Product::create(['shop_id' => $shop->id, 'name' => 'Flag', 'cost' => 100, 'price' => 150, 'stock' => 9]);

        $this->post(route('app.productSources.claimProduct', $plain->id), [
            'supplier_id' => $sujon->id, 'qty' => 9,
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_id', $plain->id)->whereNull('product_variant_id')->first();
        $this->assertNotNull($line);
        $this->assertEquals(9.0, (float) $line->stock);
        $this->assertEquals(100.0, (float) $line->cost); // defaulted to the mirror's cost
        $this->assertEquals(9.0, (float) $plain->fresh()->stock);
    }

    public function test_barcode_labels_carries_open_for_supplier(): void
    {
        [, $p, , , $sujon] = $this->shopWithLine();
        $this->grantFeature(\App\Models\Shop::find(\App\Support\Tenancy::id()), 'barcode_printing');

        $this->get(route('app.barcodeLabels.index').'?product='.$p->id.'&supplier='.$sujon->id)
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('openFor', $p->id)
                ->where('openForSupplier', $sujon->id)
            );
    }

    public function test_both_tools_are_feature_gated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers'); // no source_lines
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'X', 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $this->post(route('app.productSources.claimProduct', $p->id), ['supplier_id' => null, 'qty' => 5])
            ->assertForbidden();
    }
}
