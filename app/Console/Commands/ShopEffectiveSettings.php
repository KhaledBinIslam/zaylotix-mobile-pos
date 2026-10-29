<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Support\ShopCustomization;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 40 (docs/CUSTOMIZATION_AUDIT.md-এর ডিবাগ রেসিপির ১ নম্বর টুল) —
 * read-only: কোনো শপের প্রতিটি কাস্টমাইজযোগ্য সেটিং কার্যকর মান ও উৎস
 * (শপ/ব্যবসা/ডিফল্ট) সহ দেখায়। --all দিলে প্রতিটি শপের সেটিংস resolve হয়
 * কি না যাচাই করে (খারাপ JSON/ডেটা ধরা পড়ে) — ডিপ্লয় চেকলিস্টের ধাপ।
 */
class ShopEffectiveSettings extends Command
{
    protected $signature = 'shop:effective-settings {shop?} {--all}';

    protected $description = 'কোন কাস্টমাইজেশন সেটিং কোন স্তর (শপ/ব্যবসা/ডিফল্ট) থেকে আসছে দেখায়; --all = সব শপ resolve হয় কি না যাচাই';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->validateAll();
        }

        $shopId = $this->argument('shop');
        if (! $shopId) {
            $this->error('একটা shop id দিন (php artisan shop:effective-settings 80), বা --all দিয়ে সব শপ যাচাই করুন।');

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($shopId);
        if (! $shop) {
            $this->error("Shop {$shopId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $header = $shop->parent_shop_id
            ? "{$shop->name} (#{$shop->id}, ব্রাঞ্চ — মূল শপ #{$shop->parent_shop_id})"
            : "{$shop->name} (#{$shop->id}, মূল শপ)";
        $this->info($header);

        foreach (ShopCustomization::effective($shop) as $group => $items) {
            $this->newLine();
            $this->line("<comment>{$group}</comment>");
            $this->table(
                ['সেটিং', 'কার্যকর মান', 'উৎস'],
                array_map(fn ($row) => [$row['label'], $row['value'], $row['source']], $items)
            );
        }

        return self::SUCCESS;
    }

    private function validateAll(): int
    {
        $failed = 0;
        Shop::withoutGlobalScopes()->orderBy('id')->chunk(100, function ($shops) use (&$failed) {
            foreach ($shops as $shop) {
                try {
                    $rows = ShopCustomization::effective($shop);
                    $overridden = collect($rows)->flatten(1)
                        ->where('source', '!=', ShopCustomization::SOURCE_DEFAULT)->count();
                    $this->line("[#{$shop->id}] {$shop->name} — OK ({$overridden}টা সেটিং কাস্টমাইজ করা)");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("[#{$shop->id}] {$shop->name} — FAIL: {$e->getMessage()}");
                }
            }
        });

        if ($failed > 0) {
            $this->error("{$failed}টা শপের সেটিংস resolve করা যায়নি — উপরে দেখুন।");

            return self::FAILURE;
        }

        $this->info('সব শপের সেটিংস ঠিকমতো resolve হচ্ছে।');

        return self::SUCCESS;
    }
}
