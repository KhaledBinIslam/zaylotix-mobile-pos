<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ShopsAccountTypeCommandsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_list_command_shows_every_shop_and_changes_nothing(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'demo']);

        $this->artisan('shops:list-account-types')
            ->expectsOutputToContain($shop->name)
            ->assertExitCode(0);

        $this->assertSame('demo', $shop->fresh()->account_type);
    }

    public function test_set_account_type_without_confirm_only_previews(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'paid']);

        $this->artisan('shops:set-account-type', ['type' => 'demo', 'ids' => [$shop->id]])
            ->assertExitCode(0);

        $this->assertSame('paid', $shop->fresh()->account_type);
    }

    public function test_set_account_type_with_confirm_applies_the_change(): void
    {
        [$shopA] = $this->createShopWithOwner(['account_type' => 'paid']);
        [$shopB] = $this->createShopWithOwner(['account_type' => 'paid']);
        [$untouched] = $this->createShopWithOwner(['account_type' => 'paid']);

        $this->artisan('shops:set-account-type', ['type' => 'demo', 'ids' => [$shopA->id, $shopB->id], '--confirm' => true])
            ->assertExitCode(0);

        $this->assertSame('demo', $shopA->fresh()->account_type);
        $this->assertSame('demo', $shopB->fresh()->account_type);
        $this->assertSame('paid', $untouched->fresh()->account_type);
    }

    public function test_set_account_type_rejects_an_unknown_id(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'paid']);
        $bogusId = $shop->id + 99999;

        $this->artisan('shops:set-account-type', ['type' => 'demo', 'ids' => [$shop->id, $bogusId], '--confirm' => true])
            ->assertExitCode(1);

        $this->assertSame('paid', $shop->fresh()->account_type); // nothing applied when any id is bad
    }

    public function test_set_account_type_rejects_an_invalid_type(): void
    {
        [$shop] = $this->createShopWithOwner(['account_type' => 'paid']);

        $this->artisan('shops:set-account-type', ['type' => 'bogus', 'ids' => [$shop->id], '--confirm' => true])
            ->assertExitCode(1);

        $this->assertSame('paid', $shop->fresh()->account_type);
    }
}
