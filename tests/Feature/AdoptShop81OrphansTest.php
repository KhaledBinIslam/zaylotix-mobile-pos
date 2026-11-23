<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/**
 * The one-time Baby Park "item 3" fix (products #2296-#2298, see the
 * command's docblock) rehearsed against a synthetic copy of the real
 * rows: shops renumbered to the real 80/81, product ids forced to the
 * real ones, exactly one of the three carrying stock — as production has.
 */
class AdoptShop81OrphansTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function seedProductionShape(): void
    {
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
        $main = \App\Models\Shop::withoutGlobalScopes()->find(80);
        $this->grantFeature($main, 'shared_catalog');
        $this->grantFeature($main, 'shared_stock_pool');

        // created withoutEvents: these rows predate the branch-side
        // auto-lift (that gap is exactly what made them orphans)
        Product::withoutEvents(function () {
            foreach ([[2296, 'baby', 2800, 1], [2297, 'Waterproof', 500, 0], [2298, 'waterproof', 500, 0]] as [$id, $name, $cost, $stock]) {
                $p = Product::create(['shop_id' => 81, 'name' => $name, 'cost' => $cost, 'price' => $cost * 2, 'stock' => $stock]);
                DB::table('products')->where('id', $p->id)->update(['id' => $id]);
            }
        });
    }

    public function test_apply_creates_linked_masters_at_shop_80_with_the_same_stock(): void
    {
        $this->seedProductionShape();

        $this->artisan('products:adopt-shop81-orphans')->assertSuccessful();
        $this->assertNull(Product::withoutGlobalScopes()->find(2296)->master_product_id, 'dry-run must not link');

        $this->artisan('products:adopt-shop81-orphans', ['--apply' => true])->assertSuccessful();

        foreach ([2296 => 1, 2297 => 0, 2298 => 0] as $id => $stock) {
            $branchRow = Product::withoutGlobalScopes()->find($id);
            $this->assertNotNull($branchRow->master_product_id, "#{$id} must be linked");

            $master = Product::withoutGlobalScopes()->find($branchRow->master_product_id);
            $this->assertSame(80, $master->shop_id);
            $this->assertEquals($stock, (float) $master->stock, 'one pooled figure');
            $this->assertEquals($stock, (float) $branchRow->stock);

            foreach ([80, 81] as $shopId) {
                $this->assertSame(1, DB::table('activity_logs')
                    ->where('action', 'product.manual_pool_adopt')->where('shop_id', $shopId)
                    ->where('meta', 'like', '%"branch_product_id":'.$id.'%')->count());
            }
        }

        $csv = glob(storage_path('app/private/backups/shop81-orphan-adopt-*.csv'));
        $this->assertNotEmpty($csv);
        foreach ($csv as $f) {
            @unlink($f);
        }
    }

    public function test_refuses_when_a_row_no_longer_matches_the_approved_plan(): void
    {
        $this->seedProductionShape();
        DB::table('products')->where('id', 2297)->update(['deleted_at' => now()]);

        $this->artisan('products:adopt-shop81-orphans', ['--apply' => true])->assertFailed();

        $this->assertNull(Product::withoutGlobalScopes()->find(2296)->master_product_id, 'a refusal must change nothing');
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', 80)->where('name', 'baby')->count());
    }
}
