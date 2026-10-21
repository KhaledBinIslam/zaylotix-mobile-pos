<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Khaled's explicit, one-off ask — every demo shop's login shared one
 * password already (DemoShopSeeder's own DEMO_SHOP_PASSWORD, default
 * '1234'), but a demo shop seeded under an older/different value, or
 * whose owner later changed their own password, could drift from that.
 * This is a deliberate, scoped action against shops already flagged
 * account_type='demo' (showcase/sales-demo shops, never real paying
 * customers) — not a change to SeedGuard's own refuse-to-seed-weak-
 * passwords-in-production guard, which still protects every OTHER
 * account exactly as before.
 */
class ResetDemoPasswords extends Command
{
    protected $signature = 'demo:reset-password {--password=1234}';

    protected $description = "Set every demo shop's (account_type=demo) users to the given password — scoped to demo shops only.";

    public function handle(): int
    {
        $password = $this->option('password');

        $shopIds = Shop::where('account_type', 'demo')->pluck('id');
        if ($shopIds->isEmpty()) {
            $this->info('কোনো demo শপ পাওয়া যায়নি।');

            return self::SUCCESS;
        }

        // Each user's own update() call, not a single mass Builder::update()
        // — this is the only path guaranteed to run User's 'hashed' password
        // cast; a bulk query-builder update bypassing Eloquent attribute
        // casting here would risk writing the password in PLAIN TEXT.
        $users = User::whereIn('shop_id', $shopIds)->get();
        foreach ($users as $user) {
            $user->update(['password' => $password]);
        }

        $this->info("{$users->count()} জন ইউজারের পাসওয়ার্ড {$shopIds->count()}টা demo শপে রিসেট করা হলো।");

        return self::SUCCESS;
    }
}
