<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * Baby Park live: the invoice view only ever showed a button to START a
 * new return/exchange — a return already processed against this sale
 * (SalesReturn, written by ReturnController::process()) never surfaced
 * anywhere on the invoice itself. This is read-only: no new return logic.
 */
class SaleShowReturnsListTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_sale_show_lists_a_prior_return_against_it(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['cash_balance' => 1000]);
        $this->grantFeature($shop, 'returns');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Shirt', 'cost' => 50, 'price' => 100, 'stock' => 3]);
        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 200, 'discount' => 0, 'total' => 200, 'profit' => 100, 'payment_mode' => 'cash',
        ]);
        $item = SaleItem::create([
            'shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'unit_factor' => 1, 'qty' => 2, 'price' => 100, 'discount' => 0, 'cost' => 50,
        ]);

        $this->actingAs($owner, 'web')->postJson('/app/returns/process', [
            'sale_id' => $sale->id,
            'action' => 'refund',
            'lines' => [['sale_item_id' => $item->id, 'qty' => 1, 'condition' => 'resalable']],
        ])->assertOk();

        $page = $this->actingAs($owner, 'web')->get(route('app.sales.show', $sale->id))
            ->assertOk()->assertInertia(fn ($p) => $p
                ->has('returns', 1)
                ->where('returns.0.qty', '1.000')
                ->where('returns.0.condition', 'resalable')
                ->where('returns.0.sale_item.product_name', 'Shirt')
            );
    }

    public function test_sale_show_returns_is_empty_when_nothing_was_returned(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $sale = Sale::create([
            'shop_id' => $shop->id, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 200, 'discount' => 0, 'total' => 200, 'profit' => 100, 'payment_mode' => 'cash',
        ]);

        $this->actingAs($owner, 'web')->get(route('app.sales.show', $sale->id))
            ->assertOk()->assertInertia(fn ($p) => $p->has('returns', 0));
    }
}
