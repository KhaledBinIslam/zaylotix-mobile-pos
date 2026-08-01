<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Shop;
use App\Notifications\LowStockAlert;
use App\Support\Tenancy;
use Illuminate\Console\Command;

class SendLowStockAlerts extends Command
{
    protected $signature = 'zaylotix:low-stock-alerts';

    protected $description = 'Notify shop owners about products at/below their configured reorder point (feature: low_stock_alerts).';

    public function handle(): int
    {
        $shops = Shop::with('owner')
            ->where('status', 'active')
            ->whereHas('features', fn ($q) => $q->where('key', 'low_stock_alerts'))
            ->get();

        $sent = 0;

        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (! $owner) {
                continue;
            }

            Tenancy::set($shop->id);

            $lowStockProducts = Product::whereNotNull('reorder_point')
                ->whereColumn('stock', '<=', 'reorder_point')
                ->get();

            foreach ($lowStockProducts as $product) {
                // never send the same product's alert more than once per day
                $alreadySentToday = $owner->notifications()
                    ->where('type', LowStockAlert::class)
                    ->whereDate('created_at', now()->toDateString())
                    ->where('data->product_id', $product->id)
                    ->exists();

                if ($alreadySentToday) {
                    continue;
                }

                $owner->notify(new LowStockAlert($product));
                $sent++;
            }

            Tenancy::clear();
        }

        $this->info("Sent {$sent} low-stock alert(s).");

        return self::SUCCESS;
    }
}
