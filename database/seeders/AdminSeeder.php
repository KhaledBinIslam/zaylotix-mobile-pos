<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Support\SeedGuard;
use Illuminate\Database\Seeder;

/**
 * Password controlled by ADMIN_SEED_PASSWORD in .env — defaults to
 * 'password' for local dev (see SeedGuard), but refuses to run at all in
 * production if that var is missing, blank, or still a well-known weak
 * value, since this account has super_admin access to every shop.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@zaylotix.com'],
            ['name' => 'Khaled Bin Islam', 'password' => SeedGuard::password('ADMIN_SEED_PASSWORD', 'password'), 'role' => 'super_admin']
        );
    }
}
