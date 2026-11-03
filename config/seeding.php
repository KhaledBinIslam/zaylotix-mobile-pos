<?php

/*
 * Seed passwords, read through config rather than env() directly.
 *
 * `env()` only works while the .env file is being read — once
 * `config:cache` has run it returns null everywhere outside this
 * directory. SeedGuard called it directly, so caching config (worth
 * ~380ms of server time per request on this host) would have made every
 * seeder refuse to run with "ADMIN_SEED_PASSWORD is not set".
 *
 * It fails closed rather than open, so nothing unsafe was ever possible
 * — but a seeder that refuses for a reason nobody can see from the
 * message is its own kind of trap.
 */
return [
    'admin_password' => env('ADMIN_SEED_PASSWORD'),
    'demo_shop_password' => env('DEMO_SHOP_PASSWORD'),
];
