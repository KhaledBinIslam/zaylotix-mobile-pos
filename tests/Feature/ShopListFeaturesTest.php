<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopListFeaturesTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_lists_a_shops_granted_features(): void
    {
        [$shop] = $this->createShopWithOwner();
        $this->grantFeature($shop, 'low_stock_alerts');
        $this->grantFeature($shop, 'online_business');

        $this->artisan('shop:list-features', ['--shop' => $shop->id])
            ->expectsOutputToContain('low_stock_alerts')
            ->expectsOutputToContain('online_business')
            ->assertSuccessful();
    }

    public function test_also_lists_every_branchs_own_features_for_a_main_shop(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branch] = $this->createShopWithOwner(['parent_shop_id' => $main->id]);
        $this->grantFeature($branch, 'low_stock_alerts');

        $output = $this->artisan('shop:list-features', ['--shop' => $main->id]);
        $output->expectsOutputToContain("শাখা, মূল শপ: {$main->id}")
            ->expectsOutputToContain('low_stock_alerts')
            ->assertSuccessful();
    }

    public function test_a_branch_given_directly_does_not_also_list_its_siblings(): void
    {
        [$main] = $this->createShopWithOwner();
        [$branchA] = $this->createShopWithOwner(['parent_shop_id' => $main->id, 'name' => 'Branch A']);
        [$branchB] = $this->createShopWithOwner(['parent_shop_id' => $main->id, 'name' => 'Branch B']);

        $this->artisan('shop:list-features', ['--shop' => $branchA->id])
            ->doesntExpectOutputToContain('Branch B')
            ->assertSuccessful();
    }

    public function test_a_shop_with_no_features_shows_a_clear_message_not_a_blank(): void
    {
        [$shop] = $this->createShopWithOwner();

        $this->artisan('shop:list-features', ['--shop' => $shop->id])
            ->expectsOutputToContain('কোনো ফিচার চালু নেই')
            ->assertSuccessful();
    }
}
