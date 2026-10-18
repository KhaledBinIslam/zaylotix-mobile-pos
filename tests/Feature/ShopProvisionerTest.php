<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Models\Unit;
use App\Support\ShopProvisioner;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 0.1/0.2/0.3 fixes — see docs/FIX_PLAN.md.
 */
class ShopProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_rejects_a_racing_duplicate_phone_even_past_request_validation(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);

        ShopProvisioner::provision(
            ['business_type_id' => $type->id, 'name' => 'First', 'phone' => '01700000001', 'lang' => 'bn'],
            'Owner', '01700000001', null, 'password'
        );

        $this->expectException(ValidationException::class);

        ShopProvisioner::provision(
            ['business_type_id' => $type->id, 'name' => 'Second (race)', 'phone' => '01700000001', 'lang' => 'bn'],
            'Owner Two', '01700000001', null, 'password'
        );
    }

    public function test_provision_does_not_block_two_different_phones(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);

        ShopProvisioner::provision(['business_type_id' => $type->id, 'name' => 'A', 'phone' => '01700000002', 'lang' => 'bn'], 'Owner A', '01700000002', null, 'password');
        ShopProvisioner::provision(['business_type_id' => $type->id, 'name' => 'B', 'phone' => '01700000003', 'lang' => 'bn'], 'Owner B', '01700000003', null, 'password');

        $this->assertSame(2, Shop::withoutGlobalScopes()->count());
    }

    public function test_provisioning_under_a_business_type_with_no_matching_config_entry_falls_back_to_general_defaults(): void
    {
        $type = BusinessType::create(['slug' => 'this-slug-has-no-config-entry', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);

        $shop = ShopProvisioner::provision(
            ['business_type_id' => $type->id, 'name' => 'Driftless', 'phone' => '01700000004', 'lang' => 'bn'],
            'Owner', '01700000004', null, 'password'
        );

        $expectedCategoryCount = count(config('business_types.general.categories'));
        $expectedUnitCount = count(config('business_types.general.units'));

        $this->assertSame($expectedCategoryCount, ProductCategory::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertSame($expectedUnitCount, Unit::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        $this->assertGreaterThan(0, $expectedCategoryCount); // sanity: the fallback itself isn't empty
    }

    public function test_provision_clears_tenancy_but_provision_keeping_tenancy_leaves_it_set(): void
    {
        $type = BusinessType::create(['slug' => 'grocery', 'label_bn' => 'x', 'label_en' => 'x', 'is_active' => true]);

        ShopProvisioner::provision(['business_type_id' => $type->id, 'name' => 'A', 'phone' => '01700000005', 'lang' => 'bn'], 'Owner', '01700000005', null, 'password');
        $this->assertNull(Tenancy::id());

        $shop = ShopProvisioner::provisionKeepingTenancy(['business_type_id' => $type->id, 'name' => 'B', 'phone' => '01700000006', 'lang' => 'bn'], 'Owner', '01700000006', null, 'password');
        $this->assertSame($shop->id, Tenancy::id());
        Tenancy::clear();
    }
}
