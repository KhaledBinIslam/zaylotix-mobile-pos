<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * A distinct, browsable "what's coming due" list — the daily alert
 * notification tells the owner *that* something is expiring, this report
 * is where they see everything at once, only shown for shops with
 * batch_tracking (pharmacy/supershop/pharmacy-like verticals).
 */
class ExpiringSoonReportTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_expiring_soon_lists_batches_within_60_days_ordered_soonest_first(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'batch_tracking');
        $this->grantFeature($shop, 'reports');
        $product = Product::create(['shop_id' => $shop->id, 'name' => 'Napa', 'cost' => 1, 'price' => 2, 'stock' => 100]);

        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'FAR', 'expiry_date' => now()->addDays(50)->toDateString(), 'qty' => 10]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'SOON', 'expiry_date' => now()->addDays(5)->toDateString(), 'qty' => 20]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'TOO-FAR', 'expiry_date' => now()->addDays(90)->toDateString(), 'qty' => 30]);
        ProductBatch::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'batch_no' => 'EMPTY', 'expiry_date' => now()->addDays(1)->toDateString(), 'qty' => 0]); // sold out, must be excluded

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=month');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->has('expiringSoon', 2) // SOON and FAR only — TOO-FAR is past 60 days, EMPTY has no qty left
            ->where('expiringSoon.0.batch_no', 'SOON')
            ->where('expiringSoon.1.batch_no', 'FAR')
        );
    }

    public function test_expiring_soon_is_null_without_batch_tracking_feature(): void
    {
        [$shop, $owner] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'reports');

        $response = $this->actingAs($owner, 'web')->get('/app/reports?preset=month');

        $response->assertOk()->assertInertia(fn ($page) => $page->where('expiringSoon', null));
    }
}
