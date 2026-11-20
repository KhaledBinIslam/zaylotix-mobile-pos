<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'zaylotix:expire-subscriptions';

    protected $description = 'Deactivate shops whose subscription has passed its expiry date.';

    /**
     * Only expires — never reactivates. Reactivation happens the moment an
     * admin records a subscription payment with a new expiry date (see
     * Admin\SubscriptionController::store), so this command can't undo a
     * manual suspension that isn't purely about an expired date.
     */
    public function handle(): int
    {
        // a billing_follows_parent branch's own subscription_expiry is
        // unused (see Shop::subscriptionShop()) — expiring it here would
        // just flip a column nothing reads; the parent's own row already
        // gets expired by this same query when its turn comes
        $expired = Shop::where('status', 'active')
            ->where('billing_follows_parent', false)
            ->whereNotNull('subscription_expiry')
            ->where('subscription_expiry', '<', now()->toDateString())
            ->update(['status' => 'inactive']);

        $this->info("Expired {$expired} shop(s).");

        return self::SUCCESS;
    }
}
