<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0.6 — see docs/FIX_PLAN.md.
 */
class ShopRepairAndSmokeTestCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_test_passes_for_every_configured_business_type(): void
    {
        foreach (array_keys(config('business_types')) as $slug) {
            BusinessType::create(['slug' => $slug, 'label_bn' => $slug, 'label_en' => $slug, 'is_active' => true]);
        }

        $this->artisan('shop:smoke-test', ['--all' => true])->assertExitCode(0);

        // the command must always roll back — no trace left behind
        $this->assertSame(0, Shop::withoutGlobalScopes()->count());
    }

    public function test_smoke_test_fails_cleanly_for_an_unknown_slug(): void
    {
        $this->artisan('shop:smoke-test', ['type' => 'not-a-real-type'])->assertExitCode(1);
    }

    public function test_repair_defaults_dry_run_reports_but_never_writes(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);
        $shop = Shop::create(['name' => 'Empty Shop', 'phone' => '01700000099', 'business_type_id' => $type->id, 'lang' => 'bn']);

        $this->artisan('shops:repair-defaults', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(0, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_repair_defaults_confirm_backfills_only_shops_missing_categories_or_units(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);
        $shop = Shop::create(['name' => 'Empty Shop', 'phone' => '01700000098', 'business_type_id' => $type->id, 'lang' => 'bn']);

        $this->artisan('shops:repair-defaults', ['--confirm' => true])->assertExitCode(0);

        $expectedCategoryCount = count(config('business_types.grocery.categories'));
        $expectedUnitCount = count(config('business_types.grocery.units'));
        $this->assertSame($expectedCategoryCount, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame($expectedUnitCount, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_repair_defaults_never_touches_a_shop_that_already_has_categories_and_units(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);
        $shop = Shop::create(['name' => 'Fine Shop', 'phone' => '01700000097', 'business_type_id' => $type->id, 'lang' => 'bn']);
        ProductCategory::create(['shop_id' => $shop->id, 'name' => 'কাস্টম', 'name_en' => 'Custom', 'emoji' => '🧩']);
        Unit::create(['shop_id' => $shop->id, 'name' => 'কাস্টম ইউনিট', 'name_en' => 'Custom unit', 'code' => 'custom']);

        $this->artisan('shops:repair-defaults', ['--confirm' => true])->assertExitCode(0);

        $this->assertSame(1, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame(1, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }
}
