<?php

namespace App\Console\Commands;

use App\Models\ProductSerial;
use App\Models\Shop;
use App\Notifications\WarrantyExpiringSoon;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Phase 3 feature 9 — "ধরন-ভিত্তিক সুবিধা" (mobile/electronics vertical, see
 * docs/FIX_PLAN.md's design note). Mirrors SendExpiryAlerts' own
 * tiered/dedup-by-tier structure exactly, just against a sold unit's
 * warranty_expiry instead of a batch's expiry_date.
 */
class SendWarrantyExpiryAlerts extends Command
{
    protected $signature = 'zaylotix:warranty-expiry-alerts';

    protected $description = "Notify shop owners as a sold unit's IMEI warranty crosses a 30/7/0-day tier (feature: warranty_expiry_alerts).";

    /** Checked tightest-first — warranty periods are typically much shorter than a batch's shelf life, so the tiers are tighter too. */
    private const TIERS = [0, 7, 30];

    public function handle(): int
    {
        $shops = Shop::with('owner')
            ->where('status', 'active')
            ->whereHas('features', fn ($q) => $q->where('key', 'warranty_expiry_alerts'))
            ->get();

        $sent = 0;

        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (! $owner) {
                continue;
            }

            Tenancy::set($shop->id);

            $expiringSerials = ProductSerial::where('status', 'sold')
                ->whereNotNull('warranty_expiry')
                ->whereDate('warranty_expiry', '<=', now()->addDays(max(self::TIERS))->toDateString())
                ->with(['product', 'saleItem.sale.customer'])
                ->get();

            foreach ($expiringSerials as $serial) {
                $daysLeft = now()->startOfDay()->diffInDays($serial->warranty_expiry->startOfDay(), false);

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
                    ->where('type', WarrantyExpiringSoon::class)
                    ->where('data->product_serial_id', $serial->id)
                    ->where('data->tier', $tier)
                    ->exists();

                if ($alreadySentForTier) {
                    continue;
                }

                $owner->notify(new WarrantyExpiringSoon($serial, $tier));
                $sent++;
            }

            Tenancy::clear();
        }

        $this->info("Sent {$sent} warranty expiry alert(s).");

        return self::SUCCESS;
    }
}
