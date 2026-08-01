<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Each user picks their own display language — it must never leak into other users on the same shop. */
class LanguageSettingTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_user_can_switch_their_own_language(): void
    {
        [, $owner] = $this->createShopWithOwner(['lang' => 'bn'], ['lang' => 'bn']);

        $this->actingAs($owner, 'web')->patch('/app/settings/lang', ['lang' => 'en'])->assertRedirect();

        $this->assertSame('en', $owner->fresh()->lang);
    }

    public function test_switching_language_does_not_change_the_shops_default_or_other_users(): void
    {
        [$shop, $owner] = $this->createShopWithOwner(['lang' => 'bn'], ['lang' => 'bn']);
        $cashier = User::create([
            'shop_id' => $shop->id, 'name' => 'Cashier', 'phone' => '01900001234',
            'password' => 'secret1', 'role' => 'staff', 'permissions' => [], 'lang' => 'bn',
        ]);

        $this->actingAs($owner, 'web')->patch('/app/settings/lang', ['lang' => 'en'])->assertRedirect();

        $this->assertSame('bn', $shop->fresh()->lang);
        $this->assertSame('bn', $cashier->fresh()->lang);
        $this->assertSame('en', $owner->fresh()->lang);
    }
}
