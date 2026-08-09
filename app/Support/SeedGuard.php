<?php

namespace App\Support;

use RuntimeException;

/**
 * Shared guard for AdminSeeder/DemoShopSeeder — both create accounts whose
 * password comes from an .env var (ADMIN_SEED_PASSWORD / DEMO_SHOP_PASSWORD)
 * so the same seeder works unattended in CI/local dev with a convenient
 * default, without ever letting that convenience reach a real deployment.
 *
 * Security audit finding (Critical): `env($key, $localDefault)` alone lets a
 * production seed run create the super_admin (or a publicly-documented demo
 * shop owner) with a well-known password ('password'/'1234') if the var is
 * simply never set, or with an EMPTY password if it's set-but-blank —
 * exactly how .env.production.example ships it, to force it to be filled in
 * deliberately. Either way, whoever notices gets full platform access. This
 * refuses to seed at all in that situation instead.
 */
class SeedGuard
{
    /** Passwords that must never be accepted once real users could reach this login, even if someone typed them into a production .env on purpose. */
    private const KNOWN_WEAK = ['password', '1234', '12345', '123456', 'admin', 'changeme'];

    public static function password(string $envKey, string $localDefault): string
    {
        $value = env($envKey);
        $isProduction = app()->environment('production');

        if ($value === null || $value === '') {
            if ($isProduction) {
                throw new RuntimeException(
                    "{$envKey} is not set. Refusing to seed with a blank/default password in production — set {$envKey} in .env first."
                );
            }

            return $localDefault; // local/testing — convenience only, never internet-facing
        }

        if ($isProduction && (strlen($value) < 8 || in_array(strtolower($value), self::KNOWN_WEAK, true))) {
            throw new RuntimeException(
                "{$envKey} is too weak for production (must be 8+ characters and not a well-known default). Refusing to seed."
            );
        }

        return $value;
    }
}
