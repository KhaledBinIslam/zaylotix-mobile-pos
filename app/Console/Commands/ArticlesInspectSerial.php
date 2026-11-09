<?php

namespace App\Console\Commands;

use App\Models\ArticleSeriesCounter;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Shop;
use App\Support\ArticleSerial;
use Illuminate\Console\Command;

/**
 * Baby Park live: picking "BABY (B)" + a blank serial offered 80004, not
 * the 20058 Khaled expected. Read-only — shows every ProductCategory row
 * on this code (a code may legitimately be shared by more than one
 * category — see ArticleController::resolveCategory()'s own comment —
 * but each one carries its own serial_start band), every
 * ArticleSeriesCounter row for it, and what nextFree() actually returns
 * for each band, so the real cause (two "BABY (B)" categories with
 * different bands, a band that was never set, or a genuine nextFree() bug)
 * is visible without a single write.
 */
class ArticlesInspectSerial extends Command
{
    protected $signature = 'articles:inspect-serial {--shop=} {--name=} {--code=}';

    protected $description = 'Read-only: shows every category on a code, its serial band, the counter, and what nextFree() would offer.';

    public function handle(): int
    {
        $code = strtoupper(trim((string) $this->option('code')));
        if ($code === '') {
            $this->error('--code=<যেমন B> দিতে হবে।');

            return self::FAILURE;
        }

        $shops = Shop::withoutGlobalScopes()
            ->when($this->option('shop'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('name'), fn ($q, $name) => $q->where('name', 'like', '%'.$name.'%'))
            ->orderBy('id')->get();

        if (! $this->option('shop') && ! $this->option('name')) {
            $this->error('--shop=<id> অথবা --name=<দোকানের নাম> দিতে হবে।');

            return self::FAILURE;
        }
        if ($shops->isEmpty()) {
            $this->error('কোনো শপ পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $shops = ArticlesSettingsDoctor::withBranchesOf($shops);

        foreach ($shops as $shop) {
            $this->newLine();
            $this->line("<fg=cyan>SHOP {$shop->id} — {$shop->name}</>");

            $categories = ProductCategory::withoutGlobalScopes()
                ->where('shop_id', $shop->id)->whereRaw('UPPER(code) = ?', [$code])->get();

            if ($categories->isEmpty()) {
                $this->line("  কোড \"{$code}\"-এ কোনো ক্যাটাগরি নেই।");

                continue;
            }

            foreach ($categories as $cat) {
                $this->line("  ক্যাটাগরি id={$cat->id} নাম=\"{$cat->name}\" serial_start=".($cat->serial_start ?? 'NULL (default 10001)'));
            }

            $mainShopId = $shop->parent_shop_id ?? $shop->id;
            $counters = ArticleSeriesCounter::where('main_shop_id', $mainShopId)->where('category_code', $code)->get();
            if ($counters->isEmpty()) {
                $this->line('  এই কোডের কোনো কাউন্টার তৈরি হয়নি এখনো (প্রথম আর্টিকেল তৈরি হলে হবে)।');
            }
            foreach ($counters as $counter) {
                $this->line("  কাউন্টার band(series_start)={$counter->series_start} next_serial={$counter->next_serial}");
            }

            $used = ArticleSerial::serialsUsed($shop, $code);
            $this->line('  ব্যবহৃত সিরিয়াল (লাইভ): '.(empty($used['live']) ? 'কোনোটা নেই' : implode(', ', $used['live'])));
            $this->line('  ব্যবহৃত সিরিয়াল (মোছা হয়েছে, খালি জায়গা): '.(empty($used['freed']) ? 'কোনোটা নেই' : implode(', ', $used['freed'])));
            $this->line('  সবচেয়ে ছোট সিরিয়াল এভার: '.($used['everLowest'] ?? 'কোনোটাই না'));

            foreach ($categories->unique('serial_start') as $cat) {
                $next = ArticleSerial::nextFree($shop, $code, $cat->serial_start);
                $this->line("  যদি serial_start={$cat->serial_start} দিয়ে ফর্ম খোলা হয় → পরের ফাঁকা সিরিয়াল: {$next}");
            }

            // real article names already on this code — the fastest way to
            // see which band each one actually belongs to
            $names = Product::withoutGlobalScopes()->where('shop_id', $shop->id)
                ->where('name', 'like', $code.'-%')->orderBy('name')->pluck('name', 'id');
            $this->line('  এই কোডে আর্টিকেলের নাম: '.$names->implode(', '));
        }

        return self::SUCCESS;
    }
}
