<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use App\Models\Shop;
use App\Support\ArticleSerial;
use Illuminate\Console\Command;

/**
 * Baby Park live: category "BABY" (code B) had its serial_start stuck at
 * 80003 — per ArticleSerial's own docblock, Khaled reads the kind of shoe
 * off the number itself (baby 20000-up), so every new Baby article was
 * being offered an 80000-series number instead of continuing 20000-up.
 * 80003 was most likely a one-off typed into the "Article number" field
 * back when serial_start was still null — resolveCategory() pins
 * serial_start from that field's value the FIRST time it's empty, so one
 * manual entry permanently moved the whole category's band. There's
 * already a UI screen for this (serialSettingsEdit/Update, the "⚙️ Serial
 * Range" button), but this does the exact same write — band reassigned,
 * counter floor bumped past whatever's already live in the new band — for
 * when it's faster to fix directly than to walk Khaled through the UI.
 */
class ArticlesSetSerialStart extends Command
{
    protected $signature = 'articles:set-serial-start {--category-id=} {--start=} {--dry-run}';

    protected $description = 'Sets a category\'s serial_start band (same effect as the Serial Range settings screen) and bumps the counter floor past whatever is already live in the new band.';

    public function handle(): int
    {
        $categoryId = $this->option('category-id');
        $start = $this->option('start');

        if (! $categoryId || $start === null || $start === '') {
            $this->error('--category-id=<id> আর --start=<সংখ্যা> দুটোই দিতে হবে।');

            return self::FAILURE;
        }
        if (! ctype_digit((string) $start) || (int) $start < 1) {
            $this->error('--start একটা ধনাত্মক সংখ্যা হতে হবে।');

            return self::FAILURE;
        }
        $start = (int) $start;

        $category = ProductCategory::withoutGlobalScopes()->find((int) $categoryId);
        if (! $category) {
            $this->error("ক্যাটাগরি id {$categoryId} পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $shop = Shop::withoutGlobalScopes()->find($category->shop_id);
        if (! $shop) {
            $this->error("ক্যাটাগরি id {$categoryId}-এর শপ পাওয়া যায়নি।");

            return self::FAILURE;
        }

        $this->line("শপ {$shop->id} — ক্যাটাগরি \"{$category->name}\" (id {$category->id}, কোড {$category->code}) — serial_start {$category->serial_start} → {$start}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $category->serial_start = $start;
        $category->save();

        $code = strtoupper(trim((string) $category->code));
        if ($code !== '') {
            // Baby Park live: the UI's own Serial Range screen bounds this
            // check only below (>= start), so a stray outlier in a totally
            // different band (here, one article that got created at 80003)
            // would get swept in and immediately bump the floor right back
            // up to 80004 — undoing the very fix this command makes. A
            // live serial only belongs to THIS band if it sits in the same
            // ten-thousands block the new start opens (Khaled's own scheme
            // is bands of 10000: ladies 10000, baby 20000, pumpi 50000,
            // slippers 90000 — see ArticleSerial's docblock).
            $blockEnd = $start + 9999;
            $inBand = array_filter(
                ArticleSerial::serialsUsed($shop, $code)['live'],
                fn ($s) => $s >= $start && $s <= $blockEnd
            );
            if ($inBand !== []) {
                ArticleSerial::bumpFloor($shop, $code, max($inBand) + 1, $start);
                $this->line('  কাউন্টার ফ্লোর বসানো হলো: '.(max($inBand) + 1));
            }
        }

        $this->info('  ঠিক হয়ে গেছে।');

        return self::SUCCESS;
    }
}
