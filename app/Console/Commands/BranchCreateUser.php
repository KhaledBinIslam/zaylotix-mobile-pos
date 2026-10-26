<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Convenience command for creating a branch-scoped staff login without
 * going through the owner's in-app Staff page (App\Http\Controllers\App\
 * StaffController) — useful when the owner isn't available to click
 * through the UI themselves, e.g. setting up a second branch's login
 * before the owner has decided the phone number. See docs/BABY_PARK_PLAN.md.
 *
 * This app has no "manager" role — only `owner` and `staff` (role-gated by
 * per-section permissions in config/staff_permissions.php, not a fixed
 * tier). A user created here is automatically fixed to the given shop_id
 * (TenantScope + no branch-switch ability for non-owners), so it only ever
 * sees that one branch's own data — no extra restriction code needed.
 * Grants every available permission by default; the owner can narrow it
 * later from the Staff page.
 */
class BranchCreateUser extends Command
{
    protected $signature = 'branch:create-user {shop_id} {phone} {name} {--password=}';

    protected $description = 'Create a staff login fixed to one branch/shop.';

    public function handle(): int
    {
        $shopId = (int) $this->argument('shop_id');
        $phone = $this->argument('phone');
        $name = $this->argument('name');

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("শপ আইডি {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        if (User::withoutGlobalScopes()->where('phone', $phone)->exists()) {
            $this->error("এই ফোন নম্বর ({$phone}) দিয়ে আগেই একটি অ্যাকাউন্ট আছে।");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(12);
        $passwordWasGenerated = ! $this->option('password');

        $user = User::create([
            'shop_id' => $shop->id,
            'name' => $name,
            'phone' => $phone,
            'password' => $password,
            'role' => 'staff',
            'permissions' => array_keys(config('staff_permissions')),
            'lang' => $shop->lang,
        ]);

        $this->info("✅ স্টাফ লগইন তৈরি হয়েছে — {$shop->name} (ID {$shop->id})-এর জন্য।");
        $this->line("User ID: {$user->id}, ফোন: {$phone}");
        if ($passwordWasGenerated) {
            $this->warn("পাসওয়ার্ড (এখনই সেভ করুন, আর দেখানো হবে না): {$password}");
        } else {
            $this->info('পাসওয়ার্ড দেওয়া অপশন থেকে সেট হয়েছে।');
        }
        $this->line('এই লগইন শুধু এই ব্রাঞ্চের ডেটা দেখবে — মালিকের মতো অন্য ব্রাঞ্চে switch করতে পারবে না।');

        return self::SUCCESS;
    }
}
