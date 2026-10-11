<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Read-only: lists every shop with its current account_type classification
 * (demo/trial/paid/internal) so Khaled can see what's live before bulk-
 * reclassifying anything. Never writes anything.
 */
class ShopsListAccountTypes extends Command
{
    protected $signature = 'shops:list-account-types';

    protected $description = 'Read-only: lists every shop with its id, name, phone, and account_type.';

    public function handle(): int
    {
        $shops = Shop::withoutGlobalScopes()->orderBy('id')->get(['id', 'name', 'phone', 'account_type', 'parent_shop_id']);

        if ($shops->isEmpty()) {
            $this->info('কোনো শপ নেই।');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Phone', 'Account type', 'Branch of'],
            $shops->map(fn (Shop $s) => [
                $s->id,
                $s->name,
                $s->phone,
                $s->account_type,
                $s->parent_shop_id ?? '—',
            ])
        );

        $this->newLine();
        $this->info($shops->count().'টা শপ পাওয়া গেছে। এই command কিছু পরিবর্তন করেনি — শুধু রিপোর্ট দেখিয়েছে।');

        return self::SUCCESS;
    }
}
