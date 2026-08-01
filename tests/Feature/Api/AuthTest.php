<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, CreatesShops;

    public function test_a_shop_user_can_log_in_and_receive_a_token(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $response = $this->postJson('/api/login', [
            'login' => $owner->phone,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user', 'shop']);
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        [, $owner] = $this->createShopWithOwner();

        $this->postJson('/api/login', [
            'login' => $owner->phone,
            'password' => 'wrong-password',
            'device_name' => 'test-device',
        ])->assertStatus(422);
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        [, $owner] = $this->createShopWithOwner();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'login' => $owner->phone,
                'password' => 'wrong-password',
                'device_name' => 'test-device',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/login', [
            'login' => $owner->phone,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Too many attempts', $response->json('errors.login.0'));
    }
}
