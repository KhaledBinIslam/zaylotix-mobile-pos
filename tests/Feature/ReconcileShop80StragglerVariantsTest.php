<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The one-time Baby Park parity fix (variants #1391 and #10250, see the
 * command's own docblock) rehearsed against a synthetic copy of the real
 * rows: shops renumbered to the real 80/81, variant ids forced to the
 * real ones, shop-81 siblings pre-deleted exactly as production has them.
 */
class ReconcileShop80StragglerVariantsTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function seedProductionShape(): void
    {
        // forceCreate so the rows get the REAL production ids (80/81) the
        // command hardcodes — created before any FK children exist, so no
        // constraint is ever violated (renumbering after the fact can't
        // work: sqlite ignores PRAGMA foreign_keys inside RefreshDatabase's
        // wrapping transaction)
        foreach ([80 => null, 81 => 80] as $id => $parentId) {
            \App\Models\Shop::forceCreate([
                'id' => $id, 'parent_shop_id' => $parentId,
                'name' => 'Test Shop '.$id, 'phone' => '017000000'.$id,
                'sales_mode' => 'both', 'lang' => 'bn', 'plan' => 'trial', 'status' => 'active',
                'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
                'cash_balance' => 0, 'bank_balance' => 0, 'capital' => 0,
                'vat_mode' => 'none', 'invoice_counter' => 1000, 'onboarded_at' => now(),
            ]);
        }

        foreach ([['L-10016', '225', 'CK', 650, 1391, 1420], ['80001', '38-39', 'PURPLE BLACK', 400, 10250, 10249]] as [$name, $size, $color, $cost, $liveId, $deadId]) {
            $mainProduct = Product::create(['shop_id' => 80, 'name' => $name, 'cost' => $cost, 'price' => $cost * 2, 'stock' => 1]);
            $branchProduct = Product::create(['shop_id' => 81, 'master_product_id' => $mainProduct->id, 'name' => $name, 'cost' => $cost, 'price' => $cost * 2, 'stock' => 1]);

            $live = ProductVariant::create(['shop_id' => 80, 'product_id' => $mainProduct->id, 'size' => $size, 'color' => $color, 'stock' => 1, 'cost' => $cost]);
            DB::table('product_variants')->where('id', $live->id)->update(['id' => $liveId]);

            $dead = ProductVariant::create(['shop_id' => 81, 'product_id' => $branchProduct->id, 'size' => $size, 'color' => $color, 'stock' => 1, 'cost' => $cost]);
            DB::table('product_variants')->where('id', $dead->id)->update(['id' => $deadId, 'deleted_at' => now()->subDay()]);
        }
    }

    public function test_apply_deletes_both_shop80_stragglers_and_logs_once_per_shop(): void
    {
        $this->seedProductionShape();

        $this->artisan('products:reconcile-shop80-straggler-variants')->assertSuccessful();
        $this->assertNull(ProductVariant::withoutGlobalScopes()->find(1391)->deleted_at, 'dry-run must not delete');

        $this->artisan('products:reconcile-shop80-straggler-variants', ['--apply' => true])->assertSuccessful();

        foreach ([1391, 10250] as $id) {
            $this->assertNotNull(ProductVariant::withoutGlobalScopes()->find($id)->deleted_at);
            foreach ([80, 81] as $shopId) {
                $this->assertSame(1, DB::table('activity_logs')
                    ->where('action', 'variant.manual_pool_reconcile')
                    ->where('subject_id', $id)->where('shop_id', $shopId)->count(),
                    'exactly one log row per shop per variant — the cascade must not double-log');
            }
        }

        // siblings stay exactly as the owner left them
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->find(1420)->deleted_at);
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->find(10249)->deleted_at);

        $csv = glob(storage_path('app/private/backups/shop80-straggler-variants-*.csv'));
        $this->assertNotEmpty($csv, 'pre-image CSV must be written');
        foreach ($csv as $f) {
            @unlink($f);
        }
    }

    public function test_refuses_when_the_live_state_no_longer_matches_the_approved_plan(): void
    {
        $this->seedProductionShape();
        DB::table('product_variants')->where('id', 1391)->update(['deleted_at' => now()]);

        $this->artisan('products:reconcile-shop80-straggler-variants', ['--apply' => true])->assertFailed();

        $this->assertNull(ProductVariant::withoutGlobalScopes()->find(10250)->deleted_at, 'a refusal must change nothing at all');
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'variant.manual_pool_reconcile')->count());
    }
}
