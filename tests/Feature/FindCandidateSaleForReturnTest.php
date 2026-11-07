<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Read-only helper for the Baby Park P-50002 data fix — confirms it finds the right row and writes nothing. */
class FindCandidateSaleForReturnTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_finds_matching_sale_item_and_writes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner(['name' => 'Baby Park']);
        \App\Support\Tenancy::set($shop->id);
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'P-50002', 'cost' => 900, 'price' => 1490, 'stock' => 5]);
        $sale = Sale::create(['shop_id' => $shop->id, 'invoice_no' => 'INV-5001', 'date' => now()->toDateString(), 'time' => now()->toTimeString(), 'subtotal' => 1490, 'discount' => 0, 'total' => 1490, 'profit' => 500, 'payment_mode' => 'cash']);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'P-50002', 'variant_label' => '39, PURPLE', 'unit_factor' => 1, 'qty' => 1, 'price' => 1490, 'discount' => 0, 'cost' => 900]);

        // a real `php artisan` run has NO current-shop context at all —
        // clearing it here is what actually caught the live bug (Sale's
        // own TenantScope silently loading null when eager-loaded by name,
        // which "with Tenancy left set from createShopWithOwner" hid)
        \App\Support\Tenancy::clear();

        $this->artisan('returns:find-candidate', ['--shop' => 'Baby Park', '--product' => 'P-50002', '--size' => '39'])
            ->assertSuccessful()
            ->expectsOutputToContain('INV-5001');

        // back to this shop's own context for the assertions below — the
        // command itself must work with NO context (see above), but these
        // checks are reading this test's own tenant-scoped data again
        \App\Support\Tenancy::set($shop->id);
        $this->assertSame(1, SaleItem::count()); // nothing written/duplicated
        $this->assertSame(0, \App\Models\SalesReturn::count());
    }

    public function test_unknown_shop_fails_cleanly(): void
    {
        $this->artisan('returns:find-candidate', ['--shop' => 'Nonexistent Shop XYZ', '--product' => 'P-50002'])
            ->assertFailed();
    }
}
