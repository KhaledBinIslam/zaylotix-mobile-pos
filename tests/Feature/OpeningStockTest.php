<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockIntake;
use App\Models\Supplier;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * SEAMAN Phase 6B — the opening-stock grid and the bulk sheet. The
 * spec's own test list: filled cells only; an empty cell leaves stock
 * untouched; ledger rows match (source opening_stock, supplier, cost);
 * a new variant born in the grid is printable immediately; the bulk
 * upload's dry-run changes nothing and unmatched rows are listed back.
 */
class OpeningStockTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function aGridShop(): array
    {
        [$shop, $owner] = $this->createShopWithOwner();
        foreach (['opening_stock', 'product_variants', 'suppliers', 'weighted_average_costing'] as $f) {
            $this->grantFeature($shop, $f);
        }
        Tenancy::set($shop->id);

        return [$shop, $owner];
    }

    public function test_grid_writes_only_filled_cells_with_ledger_rows(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Jubilee']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 0]);
        $v42 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 0, 'cost' => 1600]);
        $v43 = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '43', 'stock' => 0, 'cost' => 1600]);

        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $supplier->id,
            'default_cost' => 1600,
            'cells' => [
                ['variant_id' => $v42->id, 'qty' => 5],                   // default cost
                // v43 deliberately absent — the empty cell: UNCHANGED
            ],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5, (float) $v42->fresh()->stock);
        $this->assertEquals(0, (float) $v43->fresh()->stock); // empty cell never became zero-write
        $this->assertEquals(5, (float) $product->fresh()->stock);

        $row = StockIntake::where('product_variant_id', $v42->id)->sole();
        $this->assertSame('opening_stock', $row->source);
        $this->assertSame($supplier->id, $row->supplier_id);
        $this->assertEquals(1600, (float) $row->unit_cost);
        $this->assertSame(1, StockIntake::where('shop_id', $shop->id)->count()); // only the filled cell
    }

    public function test_a_new_variant_born_in_the_grid_is_printable_immediately(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->actingAs($owner, 'web');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Helmet', 'cost' => 100, 'price' => 200, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'White', 'stock' => 0]);

        $this->post(route('app.products.openingStock', $product->id), [
            'cells' => [
                ['color' => 'Pink', 'size' => null, 'qty' => 3, 'cost' => 120, 'price' => 180],
            ],
        ])->assertSessionHasNoErrors();

        $pink = ProductVariant::where('product_id', $product->id)->where('color', 'Pink')->sole();
        $this->assertSame('ZV'.$pink->id, $pink->barcode); // printable right now
        $this->assertEquals(3, (float) $pink->stock);
        $this->assertEquals(120, (float) $pink->cost);
        $this->assertEquals(180, (float) $pink->price);
        $this->assertSame('opening_stock', StockIntake::where('product_variant_id', $pink->id)->sole()->source);
    }

    public function test_the_grid_is_refused_without_the_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Plain', 'cost' => 1, 'price' => 2, 'stock' => 0]);

        $this->actingAs($owner, 'web')
            ->post(route('app.products.openingStock', $product->id), ['cells' => [['qty' => 1]]])
            ->assertForbidden();
    }

    public function test_bulk_sheet_round_trip_with_dry_run_first(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->actingAs($owner, 'web');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Socks', 'cost' => 32, 'price' => 105, 'stock' => 0]);
        $short = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'Short', 'stock' => 0, 'cost' => 32]);

        // the downloadable sheet carries the ids the upload matches by
        $sheet = $this->get(route('app.openingStock.sheet'))->assertOk()->getContent();
        $this->assertStringContainsString("{$product->id},{$short->id},Socks", $sheet);

        $csv = "product_id,variant_id,product,colour,size,current_stock,new_qty,cost\r\n"
            ."{$product->id},{$short->id},Socks,,Short,0,12,30\r\n"
            ."999999,,Ghost,,,0,5,\r\n"; // unmatched: listed back, never guessed

        // dry-run: full preview, nothing written
        $dry = $this->post(route('app.openingStock.upload'), [
            'file' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
            'dry_run' => 1,
        ])->assertOk()->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertCount(1, $dry['changes']);
        $this->assertCount(1, $dry['unmatched']);
        $this->assertEquals(0, (float) $short->fresh()->stock);
        $this->assertSame(0, StockIntake::where('shop_id', $shop->id)->count());

        // the confirmed run applies the matched row only
        $real = $this->post(route('app.openingStock.upload'), [
            'file' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
            'dry_run' => 0,
        ])->assertOk()->json();
        $this->assertSame(1, $real['applied']);
        $this->assertEquals(12, (float) $short->fresh()->stock);
        $this->assertEquals(12, (float) $product->fresh()->stock);
        $this->assertEquals(30, (float) $short->fresh()->cost);

        $row = StockIntake::where('product_variant_id', $short->id)->sole();
        $this->assertSame('opening_stock', $row->source);
        $this->assertEquals(30, (float) $row->unit_cost);
    }
}
