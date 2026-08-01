<?php

namespace App\Console\Commands;

use App\Models\ProductBatch;
use App\Models\Shop;
use App\Notifications\ExpiryAlert;
use App\Support\Tenancy;
use Illuminate\Console\Command;

class SendExpiryAlerts extends Command
{
    protected $signature = 'zaylotix:expiry-alerts';

    protected $description = 'Notify shop owners about tracked batches crossing a 90/60/30/0-day expiry tier (feature: batch_tracking).';

    /** Checked tightest-first — a batch newly within 5 days gets the "30" tier, not "90" and "60" too, since those weren't distinctly crossed by this run. */
    private const TIERS = [0, 30, 60, 90];

    public function handle(): int
    {
        $shops = Shop::with('owner')
            ->where('status', 'active')
            ->whereHas('features', fn ($q) => $q->where('key', 'batch_tracking'))
            ->get();

        $sent = 0;

        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (! $owner) {
                continue;
            }

            Tenancy::set($shop->id);

            $expiringBatches = ProductBatch::available()
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(max(self::TIERS))->toDateString())
                ->get();

            foreach ($expiringBatches as $batch) {
                $daysLeft = now()->startOfDay()->diffInDays($batch->expiry_date->startOfDay(), false);

                $tier = null;
                foreach (self::TIERS as $t) {
                    if ($daysLeft <= $t) {
                        $tier = $t;
                        break;
                    }
                }
                if ($tier === null) {
                    continue;
                }

                $alreadySentForTier = $owner->notifications()
                    ->where('type', ExpiryAlert::class)
                    ->where('data->product_batch_id', $batch->id)
                    ->where('data->tier', $tier)
                    ->exists();

                if ($alreadySentForTier) {
                    continue;
                }

                $owner->notify(new ExpiryAlert($batch, $tier));
                $sent++;
            }

            Tenancy::clear();
        }

        $this->info("Sent {$sent} expiry alert(s).");

        return self::SUCCESS;
    }
}
