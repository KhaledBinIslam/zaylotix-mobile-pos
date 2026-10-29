<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Phase 39 — the read-only voided-sale consistency checker. */
class SalesReconcileCommandTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    private function makeVoidedSale(int $shopId, int $userId): Sale
    {
        return Sale::create([
            'shop_id' => $shopId, 'invoice_no' => 'INV-'.uniqid(),
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'subtotal' => 200, 'total' => 200, 'profit' => 100, 'payment_mode' => 'cash',
            'voided_at' => now(), 'voided_reason' => 'test void', 'voided_by' => $userId,
        ]);
    }

    public function test_a_clean_state_passes(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->makeVoidedSale($shop->id, $owner->id);

        $this->artisan('sales:reconcile', ['--shop' => $shop->id])
            ->expectsOutputToContain('কোনো সমস্যা পাওয়া যায়নি')
            ->assertExitCode(0);
    }

    public function test_a_voided_sale_that_also_has_a_return_is_flagged(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Rice', 'cost' => 100, 'price' => 200, 'stock' => 5]);
        $sale = $this->makeVoidedSale($shop->id, $owner->id);
        SalesReturn::create([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'user_id' => $owner->id,
            'qty' => 1, 'refund' => 200, 'date' => now()->toDateString(), 'sale_id' => $sale->id,
        ]);

        $this->artisan('sales:reconcile', ['--shop' => $shop->id])->assertExitCode(1);
    }
}
