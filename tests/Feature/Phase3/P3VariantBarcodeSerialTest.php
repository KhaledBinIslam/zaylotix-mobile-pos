<?php

namespace Tests\Feature\Phase3;

use App\Models\ProductSerial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\Phase3Fixture;
use Tests\TestCase;

/**
 * QA PHASE 3 — E. Variants, barcodes and serial numbers. Independent oracle: raw rows.
 */
class P3VariantBarcodeSerialTest extends TestCase
{
    use Phase3Fixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p3Boot(['serial_tracking']);
    }

    private function liveVariants(int $productId)
    {
        return DB::table('product_variants')->where('product_id', $productId)->whereNull('deleted_at')->get();
    }

    private function assertNoDuplicateSizeColour(int $productId, string $after): void
    {
        $keys = $this->liveVariants($productId)->map(fn ($v) => mb_strtolower(trim((string) $v->size)).'|'.mb_strtolower(trim((string) $v->color)));
        $this->assertSame($keys->unique()->count(), $keys->count(), "two live sizes share a size+colour after {$after}: ".$keys->implode(', '));
    }

    public function test_bulk_creating_a_colour_by_size_grid_makes_exactly_the_grid_and_keeps_the_article_total_equal_to_its_sizes(): void
    {
        $p = $this->p3Product('Grid Shoe', 50, 120, 0);
        $grid = [];
        foreach (['Black', 'Brown'] as $color) {
            foreach (['40', '41', '42'] as $size) {
                $grid[] = ['size' => $size, 'color' => $color, 'stock' => 3, 'price' => 120, 'cost' => 50];
            }
        }

        $this->p3As()->post("/app/products/{$p->id}/variants/bulk", ['variants' => $grid])->assertSessionHasNoErrors();

        $this->assertCount(6, $this->liveVariants($p->id));
        $this->assertEquals(18.0, (float) DB::table('products')->where('id', $p->id)->value('stock'), '6 sizes x 3 pieces');
        $this->assertEquals(18.0, (float) $this->liveVariants($p->id)->sum('stock'));
        $this->assertNoDuplicateSizeColour($p->id, 'the first submit');
    }

    public function test_submitting_the_same_grid_twice_or_in_another_letter_case_never_creates_a_duplicate_size_and_colour(): void
    {
        $p = $this->p3Product('Dup Shoe', 50, 120, 0);
        $grid = [['size' => '40', 'color' => 'Black', 'stock' => 3], ['size' => '41', 'color' => 'Black', 'stock' => 3]];

        $this->p3As()->post("/app/products/{$p->id}/variants/bulk", ['variants' => $grid]);
        $this->p3As()->post("/app/products/{$p->id}/variants/bulk", ['variants' => $grid]); // a double tap
        $this->p3As()->post("/app/products/{$p->id}/variants/bulk", ['variants' => [['size' => '40', 'color' => 'BLACK', 'stock' => 1]]]); // another case
        $this->p3As()->post("/app/products/{$p->id}/variants", ['size' => '41', 'color' => ' black ', 'stock' => 1]); // single add, stray spaces

        $this->assertNoDuplicateSizeColour($p->id, 'repeated and re-cased submits');
        $this->assertEquals((float) $this->liveVariants($p->id)->sum('stock'), (float) DB::table('products')->where('id', $p->id)->value('stock'), 'the article still equals the sum of its sizes');
    }

    public function test_a_variant_barcode_is_unique_inside_a_shop_but_free_to_reuse_in_another_shop(): void
    {
        $p = $this->p3Product('Barcode Shoe', 50, 120, 0);
        $this->p3As()->post("/app/products/{$p->id}/variants", ['size' => '40', 'color' => 'Black', 'stock' => 1, 'barcode' => 'BC-1001'])->assertSessionHasNoErrors();

        $second = $this->p3As()->post("/app/products/{$p->id}/variants", ['size' => '41', 'color' => 'Black', 'stock' => 1, 'barcode' => 'BC-1001']);
        $second->assertSessionHasErrors('barcode');
        $this->assertSame(1, DB::table('product_variants')->where('shop_id', $this->shop->id)->where('barcode', 'BC-1001')->count());

        // the same text in a different shop is a different product
        [$other, $otherOwner] = $this->createShopWithOwner();
        $this->grantFeature($other, 'product_variants');
        $op = \App\Models\Product::create(['shop_id' => $other->id, 'name' => 'Other Shoe', 'cost' => 1, 'price' => 2, 'stock' => 0]);
        \App\Support\Tenancy::clear();
        \App\Support\Tenancy::forgetShopCache();
        $this->actingAs($otherOwner, 'web')->post("/app/products/{$op->id}/variants", ['size' => '40', 'color' => 'Black', 'stock' => 1, 'barcode' => 'BC-1001'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('product_variants')->where('shop_id', $other->id)->where('barcode', 'BC-1001')->count());
    }

    public function test_deleting_a_size_gives_its_barcode_back_but_keeps_the_history_of_what_was_sold(): void
    {
        $p = $this->p3Product('Release Shoe', 50, 120, 0);
        $v = $this->p3Variant($p, '40', 5, ['barcode' => 'BC-2002', 'price' => 120, 'cost' => 50]);
        $sale = $this->p3Sell([['product_id' => $p->id, 'product_variant_id' => $v->id, 'qty' => 5]], [['method' => 'cash', 'amount' => 600]]);
        $this->assertEquals(0.0, (float) DB::table('product_variants')->where('id', $v->id)->value('stock'));

        $this->p3As()->delete("/app/product-variants/{$v->id}")->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('product_variants')->where('id', $v->id)->value('deleted_at'), 'soft-deleted, not erased');
        $this->assertNull(DB::table('product_variants')->where('id', $v->id)->value('barcode'), 'the barcode is released');
        $this->assertSame(1, DB::table('sale_items')->where('sale_id', $sale->id)->where('product_variant_id', $v->id)->count(), 'the old bill still shows what was sold');

        $this->p3As()->post("/app/products/{$p->id}/variants", ['size' => '41', 'color' => 'Black', 'stock' => 1, 'barcode' => 'BC-2002'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('product_variants')->where('shop_id', $this->shop->id)->where('barcode', 'BC-2002')->whereNull('deleted_at')->count(), 'the freed barcode is reusable');
    }

    // ---- serial numbers

    private function serialProduct(array $imeis): \App\Models\Product
    {
        $p = $this->p3Product('Phone', 300, 500, 0);
        $this->p3As()->post("/app/products/{$p->id}/stock-in", ['qty' => count($imeis), 'cost' => 300, 'imeis' => implode("\n", $imeis)])->assertSessionHasNoErrors();

        return $p;
    }

    private function serialStatus(string $imei): string
    {
        return (string) DB::table('product_serials')->where('shop_id', $this->shop->id)->where('imei', $imei)->value('status');
    }

    public function test_a_serial_goes_in_stock_then_sold_then_back_in_stock_on_a_return_and_sold_again_on_a_fresh_bill(): void
    {
        $p = $this->serialProduct(['111', '222', '333']);
        $this->assertEquals(3.0, (float) DB::table('products')->where('id', $p->id)->value('stock'));

        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 1, 'imei' => '111']], [['method' => 'cash', 'amount' => 500]]);
        $this->assertSame('sold', $this->serialStatus('111'));
        $this->assertSame('in_stock', $this->serialStatus('222'));
        $this->assertEquals(2.0, (float) DB::table('products')->where('id', $p->id)->value('stock'));

        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'resalable']])->assertOk();
        $this->assertSame('in_stock', $this->serialStatus('111'), 'a resalable return puts that very unit back');
        $this->assertEquals(3.0, (float) DB::table('products')->where('id', $p->id)->value('stock'));

        $this->p3Sell([['product_id' => $p->id, 'qty' => 1, 'imei' => '111']], [['method' => 'cash', 'amount' => 500]]);
        $this->assertSame('sold', $this->serialStatus('111'));
    }

    public function test_voiding_a_serial_bill_puts_the_unit_back_on_the_shelf_and_in_the_serial_list(): void
    {
        $p = $this->serialProduct(['111', '222']);
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 1, 'imei' => '222']], [['method' => 'cash', 'amount' => 500]]);
        $this->assertSame('sold', $this->serialStatus('222'));

        $this->p3Void($sale)->assertSessionHas('success');

        $this->assertSame('in_stock', $this->serialStatus('222'));
        $this->assertEquals(2.0, (float) DB::table('products')->where('id', $p->id)->value('stock'));
    }

    public function test_a_damaged_return_of_a_serial_does_not_put_the_unit_back_in_stock(): void
    {
        $p = $this->serialProduct(['111', '222']);
        $sale = $this->p3Sell([['product_id' => $p->id, 'qty' => 1, 'imei' => '111']], [['method' => 'cash', 'amount' => 500]]);

        $this->p3Return($sale, [['sale_item_id' => $this->p3ItemId($sale), 'qty' => 1, 'condition' => 'damaged']])->assertOk();

        $this->assertNotSame('in_stock', $this->serialStatus('111'), 'a damaged unit must not be offered for sale again');
        $this->assertEquals(1.0, (float) DB::table('products')->where('id', $p->id)->value('stock'), 'only the other unit is on the shelf');
    }

    /**
     * CHARACTERISATION — owner decision D8. The suite's own SerialTrackingTest documents that an unknown IMEI "never
     * blocks the sale". The same rule lets an IMEI that is ALREADY SOLD be sold again: stock goes down, no serial is
     * touched. Pinned so a future change (warn / block) is conscious.
     */
    public function test_an_already_sold_imei_can_be_rung_up_again_without_any_warning_documented_behaviour(): void
    {
        $p = $this->serialProduct(['111', '222', '333']);
        $this->p3Sell([['product_id' => $p->id, 'qty' => 1, 'imei' => '111']], [['method' => 'cash', 'amount' => 500]]);

        $again = $this->p3Checkout([['product_id' => $p->id, 'qty' => 1, 'imei' => '111']], [['method' => 'cash', 'amount' => 500]]);

        $this->assertSame(200, $again->status(), 'currently accepted');
        $this->assertSame('sold', $this->serialStatus('111'));
        $this->assertEquals(1.0, (float) DB::table('products')->where('id', $p->id)->value('stock'), '3 - 2: the shelf count dropped although 111 had already left');
        $this->assertSame(2, (int) DB::table('product_serials')->where('shop_id', $this->shop->id)->where('status', 'in_stock')->count(), 'the serial list still shows 222 and 333 in stock (2) while the shelf count says 1 — the two lists now disagree');
    }

    public function test_registering_the_same_imei_twice_is_never_accepted_and_changes_nothing(): void
    {
        $p = $this->serialProduct(['111']);
        $before = $this->p3Snap();
        $ledgerBefore = DB::table('stock_intakes')->where('product_id', $p->id)->count();

        $r = $this->p3As()->post("/app/products/{$p->id}/stock-in", ['qty' => 1, 'cost' => 300, 'imeis' => '111']);

        $this->assertTrue($r->status() >= 400 || session()->has('errors'), 'a second unit with the same IMEI must not be accepted (status '.$r->status().')');
        $this->assertSame(1, (int) ProductSerial::where('imei', '111')->count());
        $this->p3AssertSame($before, $this->p3Snap(), 'the refused stock-in rolled back completely (stock, cash, ledgers)');
        $this->assertSame($ledgerBefore, DB::table('stock_intakes')->where('product_id', $p->id)->count(), 'and no intake-ledger row was left behind');
    }

    /**
     * DEFECT D9 — the duplicate IMEI is stopped only by the database's unique index, so the cashier gets the generic
     * "Oops! An Error Occurred" crash screen (HTTP 500) instead of a message naming the IMEI. Same for a pasted list
     * that repeats an IMEI inside itself. Nothing is corrupted (the transaction rolls back); it is a clean-refusal bug.
     */
    #[\PHPUnit\Framework\Attributes\Group('phase3_defect')]
    public function test_DEFECT_a_duplicate_imei_is_refused_with_a_message_not_a_server_error(): void
    {
        $p = $this->serialProduct(['111']);

        $already = $this->p3As()->post("/app/products/{$p->id}/stock-in", ['qty' => 1, 'cost' => 300, 'imeis' => '111']);
        $this->assertLessThan(500, $already->status(), 'IMEI already registered: status '.$already->status());
        $this->assertTrue(session()->has('errors') && session('errors')->has('imeis'), 'the error must name the imeis field');

        $twiceInOneList = $this->p3As()->post("/app/products/{$p->id}/stock-in", ['qty' => 2, 'cost' => 300, 'imeis' => "777\n777"]);
        $this->assertLessThan(500, $twiceInOneList->status(), 'IMEI repeated inside one pasted list: status '.$twiceInOneList->status());
    }
}
