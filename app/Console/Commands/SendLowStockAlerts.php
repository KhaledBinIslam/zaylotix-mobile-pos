<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
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
                    ->whereNull('data->product_variant_id')
                    ->exists();

                if ($alreadySentToday) {
                    continue;
                }

                $owner->notify(new LowStockAlert($product));
                $sent++;
            }

            // a specific color/size can run low while the product's own
            // summed stock still looks fine — checked independently so
            // "নীল শার্ট M" gets its own alert even when the shirt overall
            // isn't at/below its own (rarely-set) reorder_point
            $lowStockVariants = ProductVariant::whereNotNull('reorder_point')
                ->whereColumn('stock', '<=', 'reorder_point')
                ->with('product')
                ->get();

            foreach ($lowStockVariants as $variant) {
                if (! $variant->product) {
                    continue;
                }

                $alreadySentToday = $owner->notifications()
                    ->where('type', LowStockAlert::class)
                    ->whereDate('created_at', now()->toDateString())
                    ->where('data->product_variant_id', $variant->id)
                    ->exists();

                if ($alreadySentToday) {
                    continue;
                }

                $owner->notify(new LowStockAlert($variant->product, $variant));
                $sent++;
            }

            Tenancy::clear();
        }

        $this->info("Sent {$sent} low-stock alert(s).");

        return self::SUCCESS;
    }
}
