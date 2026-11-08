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

    public function test_update_corrects_cost_and_price_and_pushes_the_correction_onto_the_variant_mirror(): void
    {
        [, $p, $v, $bulu, , $buluLine] = $this->shopWithLine();

        $this->patch(route('app.productSources.update', $buluLine->id), [
            'cost' => 600, 'price' => 999,
        ])->assertSessionHasNoErrors();

        $fresh = $buluLine->fresh();
        $this->assertEquals(600.0, (float) $fresh->cost);
        $this->assertEquals(999.0, (float) $fresh->price);
        $this->assertEquals(6.0, (float) $fresh->stock); // qty untouched
        $this->assertEquals(7.0, (float) $v->fresh()->stock); // stock count untouched
        $this->assertEquals(600.0, (float) $v->fresh()->cost); // mistaken stock-in's cost corrected here too
        $this->assertEquals(999.0, (float) $v->fresh()->price);
    }

    public function test_update_on_a_plain_products_own_line_corrects_the_product_mirror(): void
    {
        [$shop, , , , $sujon] = $this->shopWithLine();
        $plain = Product::create(['shop_id' => $shop->id, 'name' => 'Flag', 'cost' => 80, 'price' => 150, 'stock' => 9]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $plain->id, 'supplier_id' => $sujon->id, 'cost' => 80, 'price' => 150, 'stock' => 9]);

        $this->patch(route('app.productSources.update', $line->id), [
            'cost' => 95, 'price' => 180,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(95.0, (float) $plain->fresh()->cost);
        $this->assertEquals(180.0, (float) $plain->fresh()->price);
        $this->assertEquals(9.0, (float) $plain->fresh()->stock); // stock untouched
    }

    public function test_update_requires_a_valid_cost(): void
    {
        [, , , , , $buluLine] = $this->shopWithLine();

        $this->patch(route('app.productSources.update', $buluLine->id), [
            'cost' => -5,
        ])->assertSessionHasErrors('cost');

        $this->assertEquals(650.0, (float) $buluLine->fresh()->cost);
    }

    public function test_destroy_removes_an_empty_line_cleanly(): void
    {
        [$shop, $p, $v, , $sujon] = $this->shopWithLine();
        $empty = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'supplier_id' => $sujon->id, 'cost' => 10, 'price' => 20, 'stock' => 0]);

        $this->delete(route('app.productSources.destroy', $empty->id))->assertSessionHasNoErrors();

        $this->assertSoftDeleted('product_sources', ['id' => $empty->id]);
        $this->assertEquals(7.0, (float) $v->fresh()->stock); // nothing to take out
    }

    public function test_destroy_takes_a_stocked_variant_line_out_of_the_mirror_too(): void
    {
        // shopWithLine()'s product.stock (0) is a raw fixture value, not
        // kept in sync with its variant's own stock (7) the way a real
        // stockIn/checkout always does — this only asserts the DELTA
        // destroy() applies to each mirror (-6 either way), same as
        // applyStockIn() applies the identical qty to both on the way in.
        [, $p, $v, , , $buluLine] = $this->shopWithLine(); // 6 pcs on this line, variant mirror 7, product mirror 0

        $this->delete(route('app.productSources.destroy', $buluLine->id))->assertSessionHasNoErrors();

        $this->assertSoftDeleted('product_sources', ['id' => $buluLine->id]);
        $this->assertEquals(1.0, (float) $v->fresh()->stock); // 7 - 6
        $this->assertEquals(-6.0, (float) $p->fresh()->stock); // 0 - 6
    }

    public function test_destroy_takes_a_stocked_plain_product_line_out_of_the_mirror(): void
    {
        [$shop, , , , $sujon] = $this->shopWithLine();
        $plain = Product::create(['shop_id' => $shop->id, 'name' => 'Flag', 'cost' => 100, 'price' => 150, 'stock' => 9]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $plain->id, 'supplier_id' => $sujon->id, 'cost' => 100, 'price' => 150, 'stock' => 9]);

        $this->delete(route('app.productSources.destroy', $line->id))->assertSessionHasNoErrors();

        $this->assertSoftDeleted('product_sources', ['id' => $line->id]);
        $this->assertEquals(0.0, (float) $plain->fresh()->stock);
    }

    public function test_update_and_destroy_are_feature_gated(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers'); // no source_lines
        \App\Support\Tenancy::set($shop->id);
        $this->actingAs($owner, 'web');
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'X', 'cost' => 1, 'price' => 2, 'stock' => 5]);
        $line = ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'cost' => 1, 'price' => 2, 'stock' => 5]);

        $this->patch(route('app.productSources.update', $line->id), ['cost' => 2])->assertForbidden();
        $this->delete(route('app.productSources.destroy', $line->id))->assertForbidden();
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
