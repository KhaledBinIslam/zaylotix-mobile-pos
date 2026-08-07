<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Password controlled by ADMIN_SEED_PASSWORD in .env — defaults to
 * 'password' (fine for local dev) but MUST be overridden to something
 * strong before this ever runs against a real public deployment, since
 * this account has super_admin access to every shop.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@zaylotix.com'],
            ['name' => 'Khaled Bin Islam', 'password' => env('ADMIN_SEED_PASSWORD', 'password'), 'role' => 'super_admin']
        );
    }
}
