<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\SiteSetting;
use App\Notifications\PaymentDueReminder;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'zaylotix:payment-reminders';

    protected $description = 'Notify shop owners whose subscription/trial is about to expire (within the admin-configured reminder window).';

    public function handle(): int
    {
        // admin-configurable (Site Settings page) — was a hardcoded
        // constant; how many days out the warning starts firing
        $reminderDays = (int) (SiteSetting::current()->reminder_days ?? 3);

        $shops = Shop::with('owner')
            ->where('status', 'active')
            ->whereNotNull('subscription_expiry')
            ->whereBetween('subscription_expiry', [now()->toDateString(), now()->addDays($reminderDays)->toDateString()])
            ->get();

        $sent = 0;

        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (! $owner) {
                continue;
            }

            // never send the same shop's owner more than one reminder per day,
            // even if the command somehow runs twice
            $alreadySentToday = $owner->notifications()
                ->where('type', PaymentDueReminder::class)
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($alreadySentToday) {
                continue;
            }

            $daysLeft = now()->startOfDay()->diffInDays($shop->subscription_expiry->startOfDay(), false);
            $owner->notify(new PaymentDueReminder($shop, (int) $daysLeft));
            $sent++;
        }

        $this->info("Sent {$sent} payment reminder(s).");

        return self::SUCCESS;
    }
}
