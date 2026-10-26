<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * One-time backfill for every product that existed before
 * docs/FIX_PLAN.md's search-normalization fix added `search_text`
 * (Product::booted()'s saving() hook only covers products saved FROM NOW
 * ON). Safe to re-run any time — it's idempotent (just recomputes the
 * same deterministic value) and cheap (one string concat per product, no
 * external calls).
 *
 * Preview-only by default; pass --confirm to actually write.
 */
class ProductsBackfillSearchText extends Command
{
    protected $signature = 'products:backfill-search-text {--dry-run} {--confirm}';

    protected $description = 'Preview (or, with --confirm, apply) recomputing search_text for every existing product.';

    public function handle(): int
    {
        $query = Product::withoutGlobalScopes()->whereNull('search_text')->orWhere('search_text', '');

        $total = Product::withoutGlobalScopes()->count();
        $missing = $query->count();

        $this->info("মোট পণ্য: {$total}, search_text খালি: {$missing}");

        if (! $this->option('confirm')) {
            $this->info('প্রিভিউ মোড — কিছু লেখা হয়নি। চালাতে: php artisan products:backfill-search-text --confirm');

            return self::SUCCESS;
        }

        $updated = 0;
        Product::withoutGlobalScopes()->chunkById(500, function ($products) use (&$updated) {
            foreach ($products as $product) {
                // save() (not a bulk update()) so Product::booted()'s
                // saving() hook computes search_text exactly the same way
                // a normal edit would — one code path, never a second,
                // slightly-different backfill-only formula.
                $product->save();
                $updated++;
            }
        });

        $this->info("আপডেট হয়েছে: {$updated} টা পণ্য।");

        return self::SUCCESS;
    }
}
