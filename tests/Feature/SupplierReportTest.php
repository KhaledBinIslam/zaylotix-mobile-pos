<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSource;
use App\Models\StockIntake;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Khaled: "kon supplier er under e koita product add ache oi supplier er
 * koto tk pay hoiche koto tk er product niche koto tk pay korche due ache
 * customsed date diao purchase price diqa ber kora jabe" — a date-range
 * filter across the supplier's history, plus a per-product/price
 * breakdown read from StockIntake (the one ledger every stock-in path
 * already writes, not just the ones that went through Purchases).
 */
class SupplierReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_price_breakdown_groups_by_product_and_unit_cost_within_range(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $supplier = Supplier::create(['name' => 'Sujon']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 0]);

        // created_at isn't mass-assignable (Eloquent would silently stamp
        // "now" and this whole test would see nothing) — forceFill after
        // create to actually backdate these rows
        $mk = fn (array $attrs, string $date) => tap(StockIntake::create($attrs), fn ($row) => $row->forceFill(['created_at' => $date])->save());
        $mk(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $supplier->id, 'qty' => 6, 'unit_cost' => 650, 'total_cost' => 3900, 'source' => 'variant_stock_in'], '2026-10-01');
        $mk(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $supplier->id, 'qty' => 4, 'unit_cost' => 650, 'total_cost' => 2600, 'source' => 'variant_stock_in'], '2026-10-05');
        // a different price, and OUTSIDE the range below — must not count
        $mk(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $supplier->id, 'qty' => 2, 'unit_cost' => 700, 'total_cost' => 1400, 'source' => 'variant_stock_in'], '2026-10-05');
        $mk(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $supplier->id, 'qty' => 99, 'unit_cost' => 650, 'total_cost' => 64350, 'source' => 'variant_stock_in'], '2026-09-01');

        $res = $this->actingAs($owner, 'web')->get(route('app.suppliers.show', $supplier->id) . '?from=2026-10-01&to=2026-10-31')
            ->assertOk();

        // ordered unit_cost desc: 700 first (the single outside-price entry), then 650 (merged)
        $res->assertInertia(fn ($page) => $page
            ->where('rangeTotals.qty', 12) // 6 + 4 + 2, September row excluded
            ->where('rangeTotals.intake_value', 7900) // 3900 + 2600 + 1400
            ->where('rangeTotals.entries', 3)
            ->has('priceBreakdown', 2) // two distinct unit_costs: 650 and 700
            ->where('priceBreakdown.0.unit_cost', 700)
            ->where('priceBreakdown.0.qty', 2)
            ->where('priceBreakdown.0.entries', 1)
            ->where('priceBreakdown.1.unit_cost', 650)
            ->where('priceBreakdown.1.qty', 10) // 6 + 4, merged across both entries
            ->where('priceBreakdown.1.entries', 2)
        );
    }

    public function test_no_range_behaves_exactly_as_before(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $supplier = Supplier::create(['name' => 'Bulu']);
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Cap', 'cost' => 100, 'price' => 150, 'stock' => 0, 'supplier_id' => $supplier->id]);
        StockIntake::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $supplier->id, 'qty' => 5, 'unit_cost' => 100, 'total_cost' => 500, 'source' => 'product_stock_in']);

        $this->actingAs($owner, 'web')->get(route('app.suppliers.show', $supplier->id))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('dateRange.from', null)
                ->where('dateRange.to', null)
                ->has('products', 1)
                ->where('rangeTotals.qty', 5) // unfiltered = everything
            );
    }

    public function test_products_list_includes_a_product_only_linked_via_a_source_line(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'source_lines');
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $sujon = Supplier::create(['name' => 'Sujon']);
        $bulu = Supplier::create(['name' => 'Bulu']);
        // the product's OWN field says Bulu — Sujon only ever shows up on a line
        $p = Product::create(['shop_id' => $shop->id, 'name' => 'Boiler Suit', 'cost' => 650, 'price' => 1090, 'stock' => 4, 'supplier_id' => $bulu->id]);
        ProductSource::create(['shop_id' => $shop->id, 'product_id' => $p->id, 'supplier_id' => $sujon->id, 'cost' => 650, 'price' => 1090, 'stock' => 4]);

        $this->actingAs($owner, 'web')->get(route('app.suppliers.show', $sujon->id))
            ->assertOk()->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.name', 'Boiler Suit'));

        // Bulu's own page must not ALSO lose its product over this
        $this->actingAs($owner, 'web')->get(route('app.suppliers.show', $bulu->id))
            ->assertOk()->assertInertia(fn ($page) => $page->has('products', 1));
    }

    public function test_payments_total_respects_the_range(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'suppliers');
        \App\Support\Tenancy::set($shop->id);
        $supplier = Supplier::create(['name' => 'Sujon', 'payable' => 1000]);
        SupplierPayment::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'user_id' => $owner->id, 'amount' => 300, 'payable_before' => 1000, 'payable_after' => 700, 'method' => 'cash', 'date' => '2026-10-10']);
        SupplierPayment::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'user_id' => $owner->id, 'amount' => 200, 'payable_before' => 700, 'payable_after' => 500, 'method' => 'cash', 'date' => '2026-11-01']);

        $this->actingAs($owner, 'web')->get(route('app.suppliers.show', $supplier->id) . '?from=2026-10-01&to=2026-10-31')
            ->assertOk()->assertInertia(fn ($page) => $page
                ->has('payments', 1)
                ->where('rangeTotals.paid', 300)
                ->where('totals.payable', 1000) // current payable is never date-filtered
            );
    }
}
