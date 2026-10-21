<?php

namespace Tests\Feature;

use App\Console\Commands\ResetDemoPasswords;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Khaled's explicit ask — scoped strictly to account_type='demo' shops, never a real paying shop. */
class ResetDemoPasswordsTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_resets_every_demo_shops_users_to_the_given_password(): void
    {
        [$demoShop, $demoOwner] = $this->createShopWithOwner(['account_type' => 'demo']);
        $demoOwner->update(['password' => 'some-old-password']);

        $this->artisan(ResetDemoPasswords::class)->assertSuccessful();

        $this->assertTrue(Hash::check('1234', $demoOwner->fresh()->password));
    }

    public function test_never_touches_a_real_paying_shops_password(): void
    {
        [$realShop, $realOwner] = $this->createShopWithOwner(['account_type' => 'paid']);
        $realOwner->update(['password' => 'their-real-password']);

        $this->artisan(ResetDemoPasswords::class)->assertSuccessful();

        $this->assertTrue(Hash::check('their-real-password', $realOwner->fresh()->password));
    }

    public function test_accepts_a_custom_password_option(): void
    {
        [$demoShop, $demoOwner] = $this->createShopWithOwner(['account_type' => 'demo']);

        $this->artisan('demo:reset-password', ['--password' => 'custom99'])->assertSuccessful();

        $this->assertTrue(Hash::check('custom99', $demoOwner->fresh()->password));
    }
}
