<?php

namespace Tests\Unit;

use App\Support\SeedGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * Security-audit fix: AdminSeeder/DemoShopSeeder must never let a production
 * seed run create a super_admin (or publicly-documented demo shop owner)
 * with a blank or well-known weak password. See SeedGuard's own docblock.
 */
class SeedGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZZ_TEST_PW');
        unset($_ENV['ZZ_TEST_PW'], $_SERVER['ZZ_TEST_PW']);
        parent::tearDown();
    }

    public function test_falls_back_to_the_local_default_outside_production_when_unset(): void
    {
        $this->assertSame('password', SeedGuard::password('ZZ_TEST_PW', 'password'));
    }

    public function test_uses_the_env_value_when_set_outside_production(): void
    {
        putenv('ZZ_TEST_PW=anything-goes-locally');
        $this->assertSame('anything-goes-locally', SeedGuard::password('ZZ_TEST_PW', 'password'));
    }

    public function test_refuses_to_seed_in_production_when_the_var_is_missing(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        SeedGuard::password('ZZ_TEST_PW', 'password');
    }

    public function test_refuses_to_seed_in_production_when_the_var_is_blank(): void
    {
        $this->app['env'] = 'production';
        putenv('ZZ_TEST_PW=');

        $this->expectException(RuntimeException::class);
        SeedGuard::password('ZZ_TEST_PW', 'password');
    }

    public function test_refuses_a_well_known_weak_password_in_production(): void
    {
        $this->app['env'] = 'production';
        putenv('ZZ_TEST_PW=password');

        $this->expectException(RuntimeException::class);
        SeedGuard::password('ZZ_TEST_PW', 'password');
    }

    public function test_refuses_a_too_short_password_in_production(): void
    {
        $this->app['env'] = 'production';
        putenv('ZZ_TEST_PW=abc123');

        $this->expectException(RuntimeException::class);
        SeedGuard::password('ZZ_TEST_PW', 'password');
    }

    public function test_accepts_a_strong_password_in_production(): void
    {
        $this->app['env'] = 'production';
        putenv('ZZ_TEST_PW=Tr0ub4dor&3-Secure');

        $this->assertSame('Tr0ub4dor&3-Secure', SeedGuard::password('ZZ_TEST_PW', 'password'));
    }
}
