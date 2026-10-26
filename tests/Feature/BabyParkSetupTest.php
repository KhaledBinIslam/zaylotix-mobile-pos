<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the net-new pieces built for Baby Park (docs/BABY_PARK_PLAN.md) —
 * NOT the underlying shared-stock-pool mechanism itself, which already has
 * its own dedicated coverage (SharedStockPoolTest.php, from the Online
 * Business module). This file proves: the setup/rollback commands are
 * idempotent and safe, the branch gets the right feature subset, and the
 * branch-scoped staff command creates a properly-isolated login.
 */
class BabyParkSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_creates_a_main_shop_and_a_branch_sharing_stock(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();

        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();
        $this->assertNotNull($main);
        $this->assertNull($main->parent_shop_id);
        $this->assertTrue($main->hasFeature('shared_stock_pool'));
        $this->assertTrue($main->hasFeature('shared_catalog'));
        $this->assertTrue($main->hasFeature('product_variants'));

        $branch = Shop::withoutGlobalScopes()->where('parent_shop_id', $main->id)->first();
        $this->assertNotNull($branch);
        $this->assertFalse($branch->hasFeature('shared_stock_pool'));
        $this->assertFalse($branch->hasFeature('shared_catalog'));
        $this->assertTrue($branch->hasFeature('product_variants'));

        // categories/units seeded on the main shop carried over to the branch
        $this->assertSame(
            ProductCategory::withoutGlobalScopes()->where('shop_id', $main->id)->count(),
            ProductCategory::withoutGlobalScopes()->where('shop_id', $branch->id)->count()
        );
        $this->assertGreaterThan(0, ProductCategory::withoutGlobalScopes()->where('shop_id', $main->id)->count());
    }

    public function test_setup_is_idempotent_and_never_duplicates(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();

        $this->assertSame(1, Shop::withoutGlobalScopes()->where('phone', '01864987754')->count());
        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();
        $this->assertSame(1, Shop::withoutGlobalScopes()->where('parent_shop_id', $main->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('shop:setup-baby-park', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Shop::withoutGlobalScopes()->where('phone', '01864987754')->count());
    }

    public function test_shared_stock_pool_actually_works_across_the_two_branches(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();
        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();
        $branch = Shop::withoutGlobalScopes()->where('parent_shop_id', $main->id)->first();

        Tenancy::set($main->id);
        $product = Product::create(['shop_id' => $main->id, 'name' => 'Kids Sneaker', 'cost' => 500, 'price' => 900, 'stock' => 0]);
        $variant = ProductVariant::create(['shop_id' => $main->id, 'product_id' => $product->id, 'size' => '32', 'color' => 'কালো', 'stock' => 20]);
        Tenancy::clear();

        \App\Support\CatalogSync::syncToBranch($main, $branch);
        $branchProduct = Product::withoutGlobalScopes()->where('shop_id', $branch->id)->where('master_product_id', $product->id)->first();
        $branchVariant = ProductVariant::withoutGlobalScopes()->where('product_id', $branchProduct->id)->first();

        Tenancy::set($branch->id);
        $branchVariant->applyStockDelta(-5);
        Tenancy::clear();

        $this->assertSame(15.0, (float) $variant->refresh()->stock);
        $this->assertSame(15.0, (float) $branchVariant->refresh()->stock);
    }

    public function test_rollback_removes_everything_when_no_sale_exists(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();

        $this->artisan('shop:setup-baby-park', ['--rollback' => true])->assertSuccessful();

        $this->assertSame(0, Shop::withoutGlobalScopes()->where('phone', '01864987754')->count());
    }

    public function test_rollback_refuses_once_a_sale_exists(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();
        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();

        Sale::withoutGlobalScopes()->create([
            'shop_id' => $main->id, 'invoice_no' => 'INV-TEST', 'date' => now(), 'time' => now(),
            'subtotal' => 100, 'total' => 100, 'payment_mode' => 'cash',
        ]);

        $this->artisan('shop:setup-baby-park', ['--rollback' => true])->assertFailed();
        $this->assertSame(1, Shop::withoutGlobalScopes()->where('phone', '01864987754')->count());
    }

    public function test_branch_create_user_is_fixed_to_that_branch_only(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();
        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();
        $branch = Shop::withoutGlobalScopes()->where('parent_shop_id', $main->id)->first();

        $this->artisan('branch:create-user', [
            'shop_id' => $branch->id, 'phone' => '01700000002', 'name' => 'Branch 2 Staff', '--password' => 'StaffPass123!',
        ])->assertSuccessful();

        $staff = User::withoutGlobalScopes()->where('phone', '01700000002')->first();
        $this->assertNotNull($staff);
        $this->assertSame($branch->id, $staff->shop_id);
        $this->assertSame('staff', $staff->role);
    }

    public function test_branch_create_user_refuses_a_duplicate_phone(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();
        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();

        $this->artisan('branch:create-user', ['shop_id' => $main->id, 'phone' => '01700000003', 'name' => 'A'])->assertSuccessful();
        $this->artisan('branch:create-user', ['shop_id' => $main->id, 'phone' => '01700000003', 'name' => 'B'])->assertFailed();

        $this->assertSame(1, User::withoutGlobalScopes()->where('phone', '01700000003')->count());
    }

    public function test_variant_defaults_setting_saves_and_is_editable(): void
    {
        $this->artisan('shop:setup-baby-park', ['--password' => 'TestPass123!'])->assertSuccessful();
        $main = Shop::withoutGlobalScopes()->where('phone', '01864987754')->first();
        $owner = User::withoutGlobalScopes()->where('shop_id', $main->id)->where('role', 'owner')->first();

        $this->assertNotNull($main->default_variant_sizes);

        $this->actingAs($owner, 'web')->patch(route('app.settings.variantDefaults'), [
            'default_variant_sizes' => '10,11,12',
            'default_variant_colors' => 'লাল, সবুজ',
        ])->assertRedirect();

        $this->assertSame('10,11,12', $main->refresh()->default_variant_sizes);
        $this->assertSame('লাল, সবুজ', $main->default_variant_colors);
    }
}
