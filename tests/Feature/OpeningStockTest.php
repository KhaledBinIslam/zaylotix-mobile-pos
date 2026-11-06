<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
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

    /**
     * Khaled live: stock came in from a new supplier at a new sell price,
     * but the grid only ever let him PRICE a brand-new cell — an existing
     * one kept its old price no matter what he typed, because nothing
     * ever sent it to the server. default_price (every cell) and a cell's
     * own price (overrides the default) now both reach an EXISTING
     * variant too, exactly like cost already did; price is a straight
     * overwrite, never blended (there's no "average sell price").
     */
    public function test_an_existing_variants_price_can_be_set_from_the_grid(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->actingAs($owner, 'web');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 0, 'cost' => 650, 'price' => 1090]);
        $l = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'L', 'stock' => 0, 'cost' => 650, 'price' => 1090]);

        $this->post(route('app.products.openingStock', $product->id), [
            'default_cost' => 650,
            'default_price' => 990, // applies to every cell that doesn't override it
            'cells' => [
                ['variant_id' => $m->id, 'qty' => 5], // picks up the default price
                ['variant_id' => $l->id, 'qty' => 3, 'price' => 1200], // its own price wins
            ],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(990, (float) $m->fresh()->price);
        $this->assertEquals(1200, (float) $l->fresh()->price);
    }

    /** No price typed anywhere — the existing price is left exactly alone, same as before this feature existed. */
    public function test_an_existing_variants_price_is_untouched_when_none_is_given(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->actingAs($owner, 'web');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 0, 'cost' => 650, 'price' => 1090]);

        $this->post(route('app.products.openingStock', $product->id), [
            'default_cost' => 650,
            'cells' => [['variant_id' => $m->id, 'qty' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(1090, (float) $m->fresh()->price);
    }

    /**
     * Khaled live: "sujon supplier dia stock tullam ... filter korle kaj
     * korchena" — the grid picked a supplier for the ledger but never
     * touched a line, so the stock it added was invisible to the supplier
     * filter and to the per-lot sticker printer. The grid now writes a
     * line too, whenever source_lines is on, same as every other
     * stock-in path.
     */
    public function test_the_grid_creates_a_source_line_when_source_lines_is_on(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->grantFeature($shop, 'source_lines');
        $this->actingAs($owner, 'web');

        $sujon = Supplier::create(['name' => 'Sujon']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 0, 'cost' => 650, 'price' => 1090]);

        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $sujon->id,
            'default_cost' => 700,
            'cells' => [['variant_id' => $m->id, 'qty' => 4]],
        ])->assertSessionHasNoErrors();

        $line = ProductSource::where('product_variant_id', $m->id)->sole();
        $this->assertSame($sujon->id, $line->supplier_id);
        $this->assertEquals(4, (float) $line->stock);
        $this->assertEquals(700, (float) $line->cost);
        $this->assertNotNull($line->barcode); // printable immediately, same as every other line
        $this->assertEquals(4, (float) $m->fresh()->stock); // the mirror still moved exactly as before
    }

    /** A second grid stock-in from the SAME supplier merges into the one line (blending its own cost) instead of creating a twin. */
    public function test_the_grid_merges_a_second_delivery_from_the_same_supplier(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->grantFeature($shop, 'source_lines');
        $this->actingAs($owner, 'web');

        $sujon = Supplier::create(['name' => 'Sujon']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);
        $m = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => 'M', 'stock' => 0, 'cost' => 650, 'price' => 1090]);

        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $sujon->id, 'default_cost' => 700,
            'cells' => [['variant_id' => $m->id, 'qty' => 4]],
        ])->assertSessionHasNoErrors();
        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $sujon->id, 'default_cost' => 800,
            'cells' => [['variant_id' => $m->id, 'qty' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, ProductSource::where('product_variant_id', $m->id)->count()); // one lot, not two
        $line = ProductSource::where('product_variant_id', $m->id)->sole();
        $this->assertEquals(6, (float) $line->stock);
        $this->assertEquals(733.33, round((float) $line->cost, 2)); // (4*700 + 2*800) / 6
    }

    /** A new cell born straight from the grid gets a line too, exactly like an existing one. */
    public function test_a_new_cell_born_in_the_grid_also_gets_a_source_line(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->grantFeature($shop, 'source_lines');
        $this->actingAs($owner, 'web');

        $sujon = Supplier::create(['name' => 'Sujon']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);

        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $sujon->id,
            'cells' => [['color' => 'Grey', 'size' => 'XXL', 'qty' => 6, 'cost' => 700, 'price' => 1090]],
        ])->assertSessionHasNoErrors();

        $grey = ProductVariant::where('product_id', $product->id)->where('color', 'Grey')->sole();
        $line = ProductSource::where('product_variant_id', $grey->id)->sole();
        $this->assertSame($sujon->id, $line->supplier_id);
        $this->assertEquals(6, (float) $line->stock);
    }

    /** Regression guard: a shop without source_lines never gets a product_sources row from the grid — byte-identical to before this feature existed. */
    public function test_the_grid_never_writes_a_line_without_source_lines(): void
    {
        [$shop, $owner] = $this->aGridShop(); // deliberately no source_lines grant
        $this->actingAs($owner, 'web');

        $supplier = Supplier::create(['name' => 'Jubilee']);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Power X', 'cost' => 1600, 'price' => 2290, 'stock' => 0]);
        $v = ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'size' => '42', 'stock' => 0, 'cost' => 1600]);

        $this->post(route('app.products.openingStock', $product->id), [
            'supplier_id' => $supplier->id, 'default_cost' => 1600,
            'cells' => [['variant_id' => $v->id, 'qty' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, ProductSource::where('shop_id', $shop->id)->count());
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

    /**
     * Seaman live, INV-1001: 'Navy Blue' typed next to an existing
     * 'Navy blue' made twin variants — a sale decremented one while the
     * owner watched the other. Every create path now refuses a
     * case/whitespace twin.
     */
    public function test_a_case_twin_variant_is_refused_on_every_create_path(): void
    {
        [$shop, $owner] = $this->aGridShop();
        $this->actingAs($owner, 'web');

        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Radium Suit', 'cost' => 650, 'price' => 1190, 'stock' => 0]);
        ProductVariant::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'color' => 'Navy blue', 'size' => 'XXL', 'stock' => 0]);

        // the opening-stock grid's "new row"
        $this->post(route('app.products.openingStock', $product->id), [
            'cells' => [['color' => 'Navy Blue', 'size' => ' XXL ', 'qty' => 4]],
        ])->assertStatus(422);

        // the single add-variant form
        $this->post(route('app.productVariants.store', $product->id), [
            'color' => 'NAVY BLUE', 'size' => 'xxl', 'stock' => 0,
        ])->assertSessionHasErrors('size');

        // the bulk grid silently skips the twin instead of creating it
        $this->post(route('app.productVariants.bulkStore', $product->id), [
            'variants' => [
                ['color' => 'navy blue', 'size' => 'XXL', 'stock' => 0],
                ['color' => 'Orange', 'size' => 'M', 'stock' => 0],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, ProductVariant::where('product_id', $product->id)->count()); // original + Orange only
        $this->assertSame(1, ProductVariant::where('product_id', $product->id)
            ->get()->filter(fn ($v) => ProductVariant::cellKey($v->color, $v->size) === ProductVariant::cellKey('Navy blue', 'XXL'))->count());
    }
}
