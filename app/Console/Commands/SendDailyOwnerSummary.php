<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Notifications\DailyOwnerSummary;
use App\Support\Reports;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Phase 3 feature 4 — "দৈনিক মালিক সারাংশ", see docs/FIX_PLAN.md's design
 * note. Every number here is read straight from the existing Reports
 * helper and the same low-stock query SendLowStockAlerts already uses —
 * no new source of truth, no paid API, no automation beyond a normal
 * scheduled command (see routes/console.php's own proc_open-disabled-host
 * note for why this MUST be registered via Schedule::call(Artisan::call)
 * rather than Schedule::command()).
 */
class SendDailyOwnerSummary extends Command
{
    protected $signature = 'zaylotix:daily-owner-summary';

    protected $description = "Nightly in-app (+ optional WhatsApp-tap) summary of today's sales/profit/top-bottom products/low stock/total due (feature: daily_owner_summary).";

    public function handle(): int
    {
        $today = now()->toDateString();

        $shops = Shop::with('owner')
            ->where('status', 'active')
            ->whereHas('features', fn ($q) => $q->where('key', 'daily_owner_summary'))
            ->get();

        $sent = 0;

        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (! $owner) {
                continue;
            }

            Tenancy::set($shop->id);

            $alreadySentToday = $owner->notifications()
                ->where('type', DailyOwnerSummary::class)
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadySentToday) {
                Tenancy::clear();
                continue;
            }

            $owner->notify(new DailyOwnerSummary(...$this->buildSummary($shop, $today)));
            $sent++;

            Tenancy::clear();
        }

        $this->info("Sent {$sent} daily owner summary notification(s).");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: ?string} [summary text, wa.me url or null] */
    private function buildSummary(Shop $shop, string $today): array
    {
        $stats = Reports::rangeStats($today, $today);
        $top = Reports::topProducts($today, $today, 1)->first();
        $bottom = Reports::bottomProducts($today, $today, 1)->first();
        $lowStockCount = Product::whereNotNull('reorder_point')->whereColumn('stock', '<=', 'reorder_point')->count();
        $totalDue = (float) Customer::sum('due');

        $lines = [
            "🌙 {$shop->name} — আজকের সারাংশ",
            'বিক্রি: ৳'.number_format((float) $stats['salesAmt']),
            'লাভ: ৳'.number_format((float) $stats['net']),
        ];
        if ($top) {
            $lines[] = "সেরা পণ্য: {$top->product_name} ({$top->qty_sold})";
        }
        // bottomProducts() only ever includes a product that sold at least
        // once (see its own docblock) — with just one sale today, top and
        // bottom are often the same single product, which isn't a useful
        // "slow mover" signal, so it's skipped rather than shown as its own
        // redundant line.
        if ($bottom && (! $top || $bottom->product_name !== $top->product_name)) {
            $lines[] = "ধীর পণ্য: {$bottom->product_name} ({$bottom->qty_sold})";
        }
        if ($lowStockCount > 0) {
            $lines[] = "⚠️ কম স্টকে {$lowStockCount}টা পণ্য — রিঅর্ডার করুন";
        }
        $lines[] = 'মোট বাকি: ৳'.number_format($totalDue);

        $text = implode("\n", $lines);

        // same '88'+digits convention every wa.me link in this app uses —
        // see resources/js/support/whatsappQueue.js's own docblock
        $waUrl = null;
        if ($shop->owner->phone) {
            $digits = preg_replace('/\D/', '', $shop->owner->phone);
            $num = '88'.preg_replace('/^88/', '', $digits);
            $waUrl = 'https://wa.me/'.$num.'?text='.rawurlencode($text);
        }

        return [$text, $waUrl];
    }
}
