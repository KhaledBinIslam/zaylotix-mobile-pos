<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsShowSettingsChildrenTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_children_flag_lists_a_shops_branches(): void
    {
        [$main] = $this->createShopWithOwner(['name' => 'Baby Park & Liberty']);
        $branch = Shop::create([
            'name' => 'Baby Park', 'phone' => 'PENDING-xyz', 'area' => 'শাখা ২',
            'parent_shop_id' => $main->id, 'sales_mode' => 'both', 'lang' => 'bn',
            'plan' => 'monthly', 'status' => 'active',
            'subscription_start' => now()->toDateString(), 'subscription_expiry' => now()->addMonth()->toDateString(),
        ]);

        $this->artisan('shops:show-settings', ['--shop' => $main->id, '--children' => true])
            ->expectsOutputToContain("id={$branch->id} name=Baby Park area=শাখা ২")
            ->assertSuccessful();
    }

    public function test_children_flag_reports_none_for_a_shop_with_no_branches(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('shops:show-settings', ['--shop' => $shop->id, '--children' => true])
            ->expectsOutputToContain('branches: (none)')
            ->assertSuccessful();
    }
}
