<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Bulk-reclassify specific shops' account_type in one go, instead of
 * visiting each shop's Edit page one by one. Preview-only unless --confirm
 * is passed — same safety pattern as variants:set-stock. Takes explicit
 * shop ids (never "all shops") so a typo can't silently reclassify
 * something that wasn't meant to change.
 */
class ShopsSetAccountType extends Command
{
    protected $signature = 'shops:set-account-type {type} {ids*} {--confirm}';

    protected $description = 'Preview (or, with --confirm, apply) setting account_type for the given shop ids.';

    public function handle(): int
    {
        $type = $this->argument('type');
        if (! in_array($type, ['demo', 'trial', 'paid', 'internal'], true)) {
            $this->error("'{$type}' সঠিক account type না। demo, trial, paid, internal — এর একটা দিন।");

            return self::FAILURE;
        }

        $ids = array_map('intval', $this->argument('ids'));
        $shops = Shop::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'name', 'phone', 'account_type']);

        $foundIds = $shops->pluck('id')->all();
        $missing = array_diff($ids, $foundIds);
        if (! empty($missing)) {
            $this->error('এই ID গুলো খুঁজে পাওয়া যায়নি: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Name', 'Phone', 'বর্তমান account_type', 'নতুন account_type'],
            $shops->map(fn (Shop $s) => [$s->id, $s->name, $s->phone, $s->account_type, $type])
        );

        if (! $this->option('confirm')) {
            $this->newLine();
            $this->info('এটা শুধু প্রিভিউ — কিছু লেখা হয়নি। ঠিক থাকলে একই কমান্ডে --confirm যোগ করে আবার চালান।');

            return self::SUCCESS;
        }

        Shop::withoutGlobalScopes()->whereIn('id', $ids)->update(['account_type' => $type]);

        $this->newLine();
        $this->info(count($ids).'টা শপের account_type "'.$type.'" করে দেওয়া হলো।');

        return self::SUCCESS;
    }
}
